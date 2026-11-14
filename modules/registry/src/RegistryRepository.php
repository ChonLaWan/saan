<?php

namespace Saan\Modules\Registry;

use App\Support\Saan\Mutation;
use App\Support\Saan\VersionConflict;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Students, guardians, links and identifiers of one school. Every query is scoped by config('school.school_id').
 * Roles: admin and principal are registrars (full access, identifiers and documents). A teacher reads only the students
 * of the homeroom classroom in users.classroom_id, never identifiers or documents, and cannot write.
 */
class RegistryRepository
{
    public const REGISTRARS = ['admin', 'principal'];
    public const NATIONALITY = ['thai', 'foreign', 'stateless', 'undetermined'];
    public const STATUS = ['studying', 'transferred_out', 'graduated', 'left'];
    public const ADMISSION = ['regular', 'transfer_in', 're_enroll', 'other'];
    public const RELATIONSHIP = ['mother', 'father', 'grandmother', 'grandfather', 'aunt', 'uncle', 'sibling', 'guardian', 'other'];
    public const STUDENT_COLUMNS = ['code', 'prefix', 'first_name', 'last_name', 'nickname', 'sex', 'birth_date', 'birth_year_be', 'nationality_status',
        'preferred_language', 'needs_interpreter', 'status', 'admitted_on', 'admission_type', 'previous_school', 'roll_number', 'classroom_id'];

    public function school(): int { return (int) config('school.school_id'); }

    public function role(): string { return (string) auth()->user()?->role; }

    public function isRegistrar(): bool { return in_array($this->role(), self::REGISTRARS, true); }

    /** Homeroom classrooms of the signed-in teacher (core rule: users.classroom_id + classrooms.homeroom_teacher_id). @return int[] */
    public function homerooms(): array
    {
        if ($this->role() !== 'teacher') return [];
        return \App\Support\Saan\TeacherScope::homeroomIds() ?? [];
    }

    /** Student ids a teacher may read are limited to the homeroom classrooms; registrars read the whole school. */
    private function visible()
    {
        $q = DB::table('students as s')->where('s.school_id', $this->school());
        if ($this->isRegistrar()) return $q;
        $homes = $this->homerooms();
        // classroom_id survives a status change and classrooms persist across years: a teacher never reads children who have left
        return $homes ? $q->whereIn('s.classroom_id', $homes)->where('s.status', 'studying') : $q->whereRaw('1 = 0');
    }

    public function student(int $id): object
    {
        return $this->visible()->where('s.id', $id)->first(['s.*']) ?? abort(404, 'ไม่พบนักเรียน');
    }

    public function classrooms(): array
    {
        $q = DB::table('classrooms')->where('school_id', $this->school())->orderBy('name');
        if (Schema::hasColumn('classrooms', 'is_active')) $q->where('is_active', true);
        if (!$this->isRegistrar()) { $homes = $this->homerooms(); $homes ? $q->whereIn('id', $homes) : $q->whereRaw('1 = 0'); }
        return $q->get(['id', 'name'])->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->all();
    }

    public function classroomName(?int $id): ?string
    {
        return $id ? DB::table('classrooms')->where('school_id', $this->school())->where('id', $id)->value('name') : null;
    }

    public function assertClassroom(?int $id): void
    {
        if ($id !== null && !DB::table('classrooms')->where('school_id', $this->school())->where('id', $id)->exists()) abort(422, 'ไม่พบห้องเรียนนี้ในโรงเรียน');
    }

    public static function codeKey(string $code): string { return mb_strtolower(trim($code)); }

    public static function fullName(?string $first, ?string $last): string { return trim(trim((string) $first).' '.trim((string) $last)); }

    public static function displayName(?string $prefix, ?string $first, ?string $last): string
    {
        $prefix = trim((string) $prefix);
        $glue = $prefix === '' || preg_match('/[\x{0E00}-\x{0E7F}]$/u', $prefix) ? '' : ' ';
        return trim($prefix.$glue.self::fullName($first, $last));
    }

    // ---------- students ----------

    public function list(array $f): array
    {
        $registrar = $this->isRegistrar();
        $q = $this->visible()->leftJoin('classrooms as c', fn ($j) => $j->on('c.id', '=', 's.classroom_id')->on('c.school_id', '=', 's.school_id'));
        if (isset($f['classroom_id']) && $f['classroom_id'] !== '') $f['classroom_id'] === 'none' ? $q->whereNull('s.classroom_id') : $q->where('s.classroom_id', (int) $f['classroom_id']);
        if (!empty($f['status'])) $q->where('s.status', $f['status']);
        if (!empty($f['nationality_status'])) $q->where('s.nationality_status', $f['nationality_status']);
        if ($registrar && !empty($f['pending_documents'])) {
            $q->where(fn ($w) => $w->whereExists(fn ($e) => $e->select(DB::raw(1))->from('reg_documents as d')->whereColumn('d.student_id', 's.id')->where('d.review_state', 'pending'))
                ->orWhereExists(fn ($e) => $e->select(DB::raw(1))->from('reg_identifiers as i')->whereColumn('i.person_id', 's.id')->where('i.person_type', 'student')->where('i.kind', 'pending')));
        }
        if (!empty($f['q'])) {
            $like = '%'.addcslashes(trim((string) $f['q']), '%_\\').'%';
            $q->where(fn ($w) => $w->where('s.name', 'like', $like)->orWhere('s.code', 'like', $like)->orWhere('s.nickname', 'like', $like));
        }
        $total = (clone $q)->count();
        $per = max(1, min(200, (int) ($f['per_page'] ?? 50))); $page = max(1, (int) ($f['page'] ?? 1));
        $rows = $q->orderByRaw('s.classroom_id is null')->orderBy('s.classroom_id')->orderBy('s.roll_number')->orderBy('s.id')
            ->forPage($page, $per)->get(['s.*', 'c.name as classroom_name']);
        $ids = $rows->pluck('id')->all();
        $restricted = DB::table('reg_student_guardians')->where('school_id', $this->school())->whereIn('student_id', $ids)->where('can_pick_up', false)->pluck('student_id')->flip();
        $idents = $docs = collect();
        if ($registrar && $ids) {
            $idents = DB::table('reg_identifiers')->where('school_id', $this->school())->where('person_type', 'student')->whereIn('person_id', $ids)->get(['person_id', 'kind', 'last4', 'verification_state'])->groupBy('person_id');
            $docs = DB::table('reg_documents')->where('school_id', $this->school())->whereIn('student_id', $ids)->where('review_state', 'pending')->selectRaw('student_id, count(*) as n')->groupBy('student_id')->pluck('n', 'student_id');
        }
        $data = $rows->map(function ($s) use ($restricted, $idents, $docs, $registrar) {
            $o = $this->present($s) + ['classroom_name' => $s->classroom_name, 'pickup_restricted' => isset($restricted[$s->id])];
            if ($registrar) {
                $o['identifiers'] = ($idents[$s->id] ?? collect())->map(fn ($i) => $this->maskIdentifier($i))->values()->all();
                $o['pending_documents'] = (int) ($docs[$s->id] ?? 0);
            }
            return $o;
        })->all();
        return ['data' => $data, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per], 'can_edit' => $registrar, 'can_see_identity' => $registrar,
            'classrooms' => $this->classrooms()];
    }

    /** Public view of a students row: no code_key, no external reference. */
    public function present(object $s): array
    {
        return [
            'id' => (int) $s->id, 'code' => $s->code, 'name' => $s->name, 'prefix' => $s->prefix, 'first_name' => $s->first_name, 'last_name' => $s->last_name,
            'nickname' => $s->nickname, 'sex' => $s->sex, 'birth_date' => $s->birth_date, 'birth_year_be' => $s->birth_year_be !== null ? (int) $s->birth_year_be : null,
            'nationality_status' => $s->nationality_status, 'preferred_language' => $s->preferred_language, 'needs_interpreter' => (bool) $s->needs_interpreter,
            'status' => $s->status, 'admitted_on' => $s->admitted_on, 'admission_type' => $s->admission_type, 'previous_school' => $s->previous_school,
            'roll_number' => $s->roll_number !== null ? (int) $s->roll_number : null, 'classroom_id' => $s->classroom_id !== null ? (int) $s->classroom_id : null,
            'version' => (int) $s->version,
        ];
    }

    public function detail(int $id): array
    {
        $s = $this->student($id);
        $registrar = $this->isRegistrar();
        $links = DB::table('reg_student_guardians as l')->join('reg_guardians as g', fn ($j) => $j->on('g.id', '=', 'l.guardian_id')->on('g.school_id', '=', 'l.school_id'))
            ->where('l.school_id', $this->school())->where('l.student_id', $id)->orderByDesc('l.is_primary')->orderBy('l.id')
            ->get(['l.*', 'g.prefix', 'g.first_name', 'g.last_name', 'g.display_name', 'g.phone_enc', 'g.email', 'g.line_id', 'g.occupation', 'g.version as guardian_version']);
        $gIdents = $registrar && $links->count() ? DB::table('reg_identifiers')->where('school_id', $this->school())->where('person_type', 'guardian')
            ->whereIn('person_id', $links->pluck('guardian_id'))->get()->groupBy('person_id') : collect();
        $guardians = $links->map(fn ($l) => [
            'link_id' => (int) $l->id, 'link_version' => (int) $l->version, 'guardian_id' => (int) $l->guardian_id, 'guardian_version' => (int) $l->guardian_version,
            'display_name' => $l->display_name, 'prefix' => $l->prefix, 'first_name' => $l->first_name, 'last_name' => $l->last_name,
            'phone' => Identity::decrypt($l->phone_enc), 'email' => $l->email, 'line_id' => $l->line_id, 'occupation' => $l->occupation,
            'relationship' => $l->relationship, 'is_primary' => (bool) $l->is_primary, 'is_emergency_contact' => (bool) $l->is_emergency_contact,
            'can_pick_up' => (bool) $l->can_pick_up, 'pickup_note' => $l->pickup_note,
            'identifiers' => $registrar ? ($gIdents[$l->guardian_id] ?? collect())->map(fn ($i) => $this->maskIdentifier($i))->values()->all() : [],
        ])->values()->all();
        $out = ['student' => $this->present($s) + ['classroom_name' => $this->classroomName($s->classroom_id)], 'guardians' => $guardians,
            'pickup_restrictions' => array_values(array_filter(array_map(fn ($g) => $g['can_pick_up'] ? null : ['guardian_id' => $g['guardian_id'], 'display_name' => $g['display_name'], 'note' => $g['pickup_note']], $guardians))),
            'can_edit' => $registrar, 'can_see_identity' => $registrar, 'classrooms' => $this->classrooms()];
        if ($registrar) {
            $out['identifiers'] = DB::table('reg_identifiers')->where('school_id', $this->school())->where('person_type', 'student')->where('person_id', $id)->orderBy('id')->get()
                ->map(fn ($i) => $this->maskIdentifier($i))->values()->all();
            $out['documents'] = DB::table('reg_documents')->where('school_id', $this->school())->where('student_id', $id)->orderBy('id')->get()
                ->map(fn ($d) => $this->presentDocument($d))->values()->all();
        }
        return $out;
    }

    public function create(array $d): array
    {
        $this->assertClassroom($d['classroom_id'] ?? null);
        $code = trim($d['code']);
        $this->assertCodeFree($code, null);
        $row = $this->normaliseStudent($d) + ['code' => $code, 'code_key' => self::codeKey($code)];
        $row['name'] = self::fullName($row['first_name'] ?? null, $row['last_name'] ?? null);
        $row += ['nationality_status' => 'thai', 'status' => 'studying', 'needs_interpreter' => false];
        try {
            $s = Mutation::insert('students', $row, 'registry.student.created', ['code' => $code]);
        } catch (QueryException) {
            abort(422, 'รหัสนักเรียนนี้มีอยู่แล้ว');
        }
        return $this->detail((int) $s->id);
    }

    public function update(int $id, int $version, array $d): array
    {
        $cur = $this->student($id);
        if (array_key_exists('classroom_id', $d)) $this->assertClassroom($d['classroom_id']);
        $changes = $this->normaliseStudent($d);
        if (isset($d['code'])) {
            $code = trim($d['code']);
            if (self::codeKey($code) !== self::codeKey((string) $cur->code)) $this->assertCodeFree($code, $id);
            $changes['code'] = $code; $changes['code_key'] = self::codeKey($code);
        }
        if (array_key_exists('first_name', $changes) || array_key_exists('last_name', $changes)) {
            $first = array_key_exists('first_name', $changes) ? $changes['first_name'] : $cur->first_name;
            $last = array_key_exists('last_name', $changes) ? $changes['last_name'] : $cur->last_name;
            // legacy rows without first_name keep their stored name until a first name exists
            if (trim((string) $first) !== '') $changes['name'] = self::fullName($first, $last);
        }
        if ($cur->code_key === null && !isset($changes['code_key'])) $changes += $this->legacyCodeKey($cur);
        try {
            Mutation::update('students', $id, $version, $changes, 'registry.student.updated', ['fields' => array_keys($changes)]);
        } catch (QueryException) {
            abort(422, 'รหัสนักเรียนนี้มีอยู่แล้ว');
        }
        return $this->detail($id);
    }

    private function normaliseStudent(array $d): array
    {
        $out = [];
        foreach (self::STUDENT_COLUMNS as $c) {
            if ($c === 'code' || !array_key_exists($c, $d)) continue;
            $v = $d[$c];
            if (is_string($v)) { $v = trim($v); if ($v === '' && !in_array($c, ['first_name'], true)) $v = null; }
            $out[$c] = $c === 'needs_interpreter' ? (bool) $v : $v;
        }
        if (!empty($out['birth_date']) && !array_key_exists('birth_year_be', $out)) $out['birth_year_be'] = (int) substr($out['birth_date'], 0, 4) + 543;
        return $out;
    }

    /** Fills the normalised key of a legacy row (inserted without code_key) when no other row of the school already owns it. */
    public function legacyCodeKey(object $cur): array
    {
        $key = self::codeKey((string) $cur->code);
        if ($key === '' || DB::table('students')->where('school_id', $this->school())->where('code_key', $key)->where('id', '!=', $cur->id)->exists()) return [];
        return ['code_key' => $key];
    }

    private function assertCodeFree(string $code, ?int $exceptId): void
    {
        $key = self::codeKey($code);
        $q = DB::table('students')->where('school_id', $this->school())->where(fn ($w) => $w->where('code_key', $key)->orWhere(fn ($x) => $x->whereNull('code_key')->whereRaw('LOWER(TRIM(code)) = ?', [$key])));
        if ($exceptId) $q->where('id', '!=', $exceptId);
        if ($q->exists()) abort(422, 'รหัสนักเรียนนี้มีอยู่แล้ว');
    }

    // ---------- guardians ----------

    public function addGuardian(int $studentId, array $d): array
    {
        $this->student($studentId);
        if (DB::table('reg_student_guardians')->where('school_id', $this->school())->where('student_id', $studentId)->count() >= 10) abort(422, 'ผูกผู้ปกครองได้ไม่เกิน 10 คนต่อนักเรียน');
        if (($d['can_pick_up'] ?? true) === false && trim((string) ($d['pickup_note'] ?? '')) === '') abort(422, 'กรุณาระบุเหตุผลหรือคำแนะนำเมื่อห้ามรับนักเรียน');
        DB::transaction(function () use ($studentId, $d) {
            if (!empty($d['guardian_id'])) {
                $g = DB::table('reg_guardians')->where('school_id', $this->school())->where('id', (int) $d['guardian_id'])->first() ?? abort(404, 'ไม่พบผู้ปกครอง');
                $gid = (int) $g->id;
            } else {
                $gid = (int) $this->insertGuardian($d['guardian'] ?? abort(422, 'ต้องระบุผู้ปกครองที่มีอยู่หรือข้อมูลผู้ปกครองใหม่'))->id;
            }
            if (DB::table('reg_student_guardians')->where('student_id', $studentId)->where('guardian_id', $gid)->exists()) abort(422, 'ผู้ปกครองคนนี้ผูกกับนักเรียนแล้ว');
            $primary = !empty($d['is_primary']);
            if ($primary) $this->demotePrimary($studentId, null);
            Mutation::insert('reg_student_guardians', [
                'student_id' => $studentId, 'guardian_id' => $gid, 'relationship' => $d['relationship'] ?? 'other', 'is_primary' => $primary,
                'is_emergency_contact' => !empty($d['is_emergency_contact']), 'can_pick_up' => $d['can_pick_up'] ?? true,
                'pickup_note' => ($d['can_pick_up'] ?? true) ? null : trim((string) $d['pickup_note']),
            ], 'registry.guardian.linked', ['student_id' => $studentId, 'guardian_id' => $gid]);
        });
        return $this->detail($studentId);
    }

    public function insertGuardian(array $g): object
    {
        $first = trim((string) ($g['first_name'] ?? '')); $last = trim((string) ($g['last_name'] ?? '')); $prefix = trim((string) ($g['prefix'] ?? ''));
        $display = mb_substr(trim((string) ($g['display_name'] ?? '')) ?: self::displayName($prefix, $first, $last), 0, 240); // column is varchar(240)
        $row = ['prefix' => $prefix ?: null, 'first_name' => $first ?: null, 'last_name' => $last ?: null, 'display_name' => $display,
            'phone_enc' => Identity::encrypt(trim((string) ($g['phone'] ?? ''))), 'email' => trim((string) ($g['email'] ?? '')) ?: null,
            'line_id' => trim((string) ($g['line_id'] ?? '')) ?: null, 'occupation' => trim((string) ($g['occupation'] ?? '')) ?: null];
        if (!empty($g['ref_code'])) $row += ['ref_code' => $g['ref_code'], 'ref_key' => self::codeKey($g['ref_code'])];
        return Mutation::insert('reg_guardians', $row, 'registry.guardian.created', ['display_name' => $display]);
    }

    public function updateGuardian(int $id, int $version, array $d): array
    {
        $cur = DB::table('reg_guardians')->where('school_id', $this->school())->where('id', $id)->first() ?? abort(404, 'ไม่พบผู้ปกครอง');
        $this->assertGuardianReachable($id);
        $ch = [];
        foreach (['prefix', 'first_name', 'last_name', 'email', 'line_id', 'occupation'] as $c) if (array_key_exists($c, $d)) $ch[$c] = trim((string) $d[$c]) ?: null;
        if (array_key_exists('phone', $d)) $ch['phone_enc'] = Identity::encrypt(trim((string) $d['phone']));
        if (array_intersect(['prefix', 'first_name', 'last_name'], array_keys($ch))) {
            $ch['display_name'] = mb_substr(self::displayName($ch['prefix'] ?? $cur->prefix, $ch['first_name'] ?? $cur->first_name, $ch['last_name'] ?? $cur->last_name) ?: $cur->display_name, 0, 240);
        }
        $this->guard(fn () => Mutation::update('reg_guardians', $id, $version, $ch, 'registry.guardian.updated', ['fields' => array_keys($ch)]), ['phone_enc']);
        $sid = DB::table('reg_student_guardians')->where('school_id', $this->school())->where('guardian_id', $id)->value('student_id');
        return $sid ? $this->detail((int) $sid) : ['guardian_id' => $id];
    }

    private function assertGuardianReachable(int $guardianId): void
    {
        if (!DB::table('reg_student_guardians')->where('school_id', $this->school())->where('guardian_id', $guardianId)->exists() && !$this->isRegistrar()) abort(404);
    }

    public function updateLink(int $id, int $version, array $d): array
    {
        $cur = DB::table('reg_student_guardians')->where('school_id', $this->school())->where('id', $id)->first() ?? abort(404, 'ไม่พบการผูกผู้ปกครอง');
        $can = array_key_exists('can_pick_up', $d) ? (bool) $d['can_pick_up'] : (bool) $cur->can_pick_up;
        $note = array_key_exists('pickup_note', $d) ? trim((string) $d['pickup_note']) : (string) $cur->pickup_note;
        if (!$can && $note === '') abort(422, 'กรุณาระบุเหตุผลหรือคำแนะนำเมื่อห้ามรับนักเรียน');
        $ch = ['can_pick_up' => $can, 'pickup_note' => $can ? null : $note];
        foreach (['relationship'] as $c) if (array_key_exists($c, $d)) $ch[$c] = $d[$c];
        foreach (['is_primary', 'is_emergency_contact'] as $c) if (array_key_exists($c, $d)) $ch[$c] = (bool) $d[$c];
        DB::transaction(function () use ($id, $version, $ch, $cur, $can) {
            Mutation::update('reg_student_guardians', $id, $version, $ch, 'registry.link.updated', ['student_id' => (int) $cur->student_id, 'fields' => array_keys($ch)]);
            if (!empty($ch['is_primary'])) $this->demotePrimary((int) $cur->student_id, $id);
            if ((bool) $cur->can_pick_up && !$can) self::revokePortalLinks((int) $cur->student_id, (int) $cur->guardian_id, $this->school());
        });
        return $this->detail((int) $cur->student_id);
    }

    public function deleteLink(int $id, int $version): array
    {
        $cur = DB::table('reg_student_guardians')->where('school_id', $this->school())->where('id', $id)->first() ?? abort(404, 'ไม่พบการผูกผู้ปกครอง');
        DB::transaction(function () use ($id, $version, $cur) {
            Mutation::delete('reg_student_guardians', $id, $version, 'registry.link.deleted');
            self::revokePortalLinks((int) $cur->student_id, (int) $cur->guardian_id, $this->school());
        });
        return $this->detail((int) $cur->student_id);
    }

    /** Registrar: guardians that no student links to any more (invisible in detail(), so they could never be found for a PDPA erasure). */
    public function unlinkedGuardians(): array
    {
        return DB::table('reg_guardians as g')->where('g.school_id', $this->school())
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('reg_student_guardians as l')->whereColumn('l.guardian_id', 'g.id')->where('l.school_id', $this->school()))
            ->orderBy('g.id')->limit(500)->get(['g.id', 'g.display_name', 'g.version'])
            ->map(fn ($g) => ['id' => (int) $g->id, 'display_name' => $g->display_name, 'version' => (int) $g->version])->all();
    }

    /** Registrar: erase a guardian with identifiers, portal account, links and acknowledgements. Refused while any student is still linked. */
    public function deleteGuardian(int $id, int $version): void
    {
        $school = $this->school();
        DB::table('reg_guardians')->where('school_id', $school)->where('id', $id)->first() ?? abort(404, 'ไม่พบผู้ปกครอง');
        // guard(): the 409 answer of a version conflict carries the current row, which must not include the encrypted phone
        $this->guard(fn () => DB::transaction(function () use ($id, $version, $school) {
            if (DB::table('reg_student_guardians')->where('school_id', $school)->where('guardian_id', $id)->lockForUpdate()->exists()) abort(422, 'ผู้ปกครองรายนี้ยังผูกกับนักเรียน ให้ยกเลิกการผูกก่อน');
            DB::table('reg_identifiers')->where('school_id', $school)->where('person_type', 'guardian')->where('person_id', $id)->delete();
            foreach (['gp_acks', 'gp_links', 'gp_accounts'] as $t) {
                if (Schema::hasTable($t)) DB::table($t)->where('school_id', $school)->where('guardian_id', $id)->delete();
            }
            if (Schema::hasTable('gp_events')) DB::table('gp_events')->where('school_id', $school)->where('guardian_id', $id)->update(['guardian_id' => null]);
            Mutation::delete('reg_guardians', $id, $version, 'registry.guardian.deleted');
        }), ['phone_enc']);
    }

    /**
     * Revokes the guardian-portal links (gp_links) of one guardian-student pair so that re-creating the relation later does not silently
     * restore portal access. Used when the relation is deleted or pickup is switched off; also by import rollback.
     */
    public static function revokePortalLinks(int $studentId, int $guardianId, int $school): void
    {
        if (!Schema::hasTable('gp_links')) return;
        DB::table('gp_links')->where('school_id', $school)->where('student_id', $studentId)->where('guardian_id', $guardianId)->whereNull('revoked_at')
            ->update(['revoked_at' => now()->toDateTimeString(), 'version' => DB::raw('version + 1'), 'updated_at' => now()->toDateTimeString()]);
    }

    private function demotePrimary(int $studentId, ?int $exceptLink): void
    {
        $q = DB::table('reg_student_guardians')->where('school_id', $this->school())->where('student_id', $studentId)->where('is_primary', true);
        if ($exceptLink) $q->where('id', '!=', $exceptLink);
        $q->update(['is_primary' => false, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
    }

    // ---------- identifiers ----------

    public function maskIdentifier(object $i): array
    {
        $o = ['kind' => $i->kind, 'last4' => $i->last4, 'verification_state' => $i->verification_state ?? null, 'issuer' => $i->issuer ?? null, 'expires_on' => $i->expires_on ?? null];
        if (isset($i->id)) $o += ['id' => (int) $i->id, 'person_type' => $i->person_type, 'person_id' => (int) $i->person_id, 'note' => $i->note, 'version' => (int) $i->version];
        $o['masked'] = $i->last4 ? '********'.$i->last4 : null;
        return $o;
    }

    public function addIdentifier(array $d): array
    {
        $this->assertPerson($d['person_type'], (int) $d['person_id']);
        $kind = $d['kind'];
        $norm = Identity::normalise($kind, $d['value'] ?? null);
        if ($err = Identity::error($kind, $norm)) abort(422, $err);
        if ($kind === 'pending' && DB::table('reg_identifiers')->where('school_id', $this->school())->where('person_type', $d['person_type'])->where('person_id', $d['person_id'])->where('kind', 'pending')->exists()) abort(422, 'มีรายการรอเอกสารอยู่แล้ว');
        $issuer = Identity::issuer($kind, $d['issuer'] ?? null);
        $hash = $norm === null ? null : Identity::hash(Identity::hashInput($kind, $norm, $issuer));
        if ($hash && DB::table('reg_identifiers')->where('school_id', $this->school())->where('kind', $kind)->whereIn('value_hash', Identity::hashes(Identity::hashInput($kind, $norm, $issuer)))->exists()) abort(422, 'เลขประจำตัวนี้มีอยู่ในระบบแล้ว');
        try {
            $row = Mutation::insert('reg_identifiers', [
                'person_type' => $d['person_type'], 'person_id' => (int) $d['person_id'], 'kind' => $kind, 'value_enc' => Identity::encrypt($norm),
                'value_hash' => $hash, 'last4' => Identity::last4($norm), 'verification_state' => $d['verification_state'] ?? 'unverified', 'note' => $d['note'] ?? null,
                'issuer' => $issuer, 'expires_on' => $d['expires_on'] ?? null,
            ], 'registry.identifier.created', ['person_type' => $d['person_type'], 'person_id' => (int) $d['person_id'], 'kind' => $kind]);
        } catch (QueryException) {
            abort(422, 'เลขประจำตัวนี้มีอยู่ในระบบแล้ว');
        }
        return $this->maskIdentifier($row);
    }

    public function updateIdentifier(int $id, int $version, array $d): array
    {
        $cur = $this->identifier($id);
        $ch = [];
        foreach (['verification_state', 'note'] as $c) if (array_key_exists($c, $d)) $ch[$c] = $d[$c];
        foreach (['expires_on'] as $c) if (array_key_exists($c, $d)) $ch[$c] = $d[$c] ?: null;
        if (!empty($d['value'])) {
            $kind = $d['kind'] ?? $cur->kind;
            $norm = Identity::normalise($kind, $d['value']);
            if ($err = Identity::error($kind, $norm)) abort(422, $err);
            $issuer = array_key_exists('issuer', $d) ? Identity::issuer($kind, $d['issuer']) : Identity::issuer($kind, $cur->issuer ?? null);
            $ch['issuer'] = $issuer;
            $hash = $norm === null ? null : Identity::hash(Identity::hashInput($kind, $norm, $issuer));
            if ($hash && DB::table('reg_identifiers')->where('school_id', $this->school())->where('kind', $kind)->whereIn('value_hash', Identity::hashes(Identity::hashInput($kind, $norm, $issuer)))->where('id', '!=', $id)->exists()) abort(422, 'เลขประจำตัวนี้มีอยู่ในระบบแล้ว');
            $ch += ['kind' => $kind, 'value_enc' => Identity::encrypt($norm), 'value_hash' => $hash, 'last4' => Identity::last4($norm)];
        }
        try {
            $row = $this->guard(fn () => Mutation::update('reg_identifiers', $id, $version, $ch, 'registry.identifier.updated',
                ['person_type' => $cur->person_type, 'person_id' => (int) $cur->person_id, 'fields' => array_keys($ch)]), ['value_enc']);
        } catch (QueryException) {
            abort(422, 'เลขประจำตัวนี้มีอยู่ในระบบแล้ว');
        }
        return $this->maskIdentifier($row);
    }

    public function deleteIdentifier(int $id, int $version): void
    {
        $this->identifier($id);
        $this->guard(fn () => Mutation::delete('reg_identifiers', $id, $version, 'registry.identifier.deleted'), ['value_enc']);
    }

    /** The only place a full identifier leaves the server. Writes an audit row that names the identifier but never contains the value. */
    public function reveal(int $id): array
    {
        $i = $this->identifier($id);
        Mutation::audit('registry.identifier.revealed', 'reg_identifiers', $id, ['person_type' => $i->person_type, 'person_id' => (int) $i->person_id, 'kind' => $i->kind]);
        return ['id' => $id, 'kind' => $i->kind, 'value' => Identity::decrypt($i->value_enc)];
    }

    private function identifier(int $id): object
    {
        return DB::table('reg_identifiers')->where('school_id', $this->school())->where('id', $id)->first() ?? abort(404, 'ไม่พบเลขประจำตัว');
    }

    private function assertPerson(string $type, int $id): void
    {
        $ok = $type === 'student'
            ? DB::table('students')->where('school_id', $this->school())->where('id', $id)->exists()
            : DB::table('reg_guardians')->where('school_id', $this->school())->where('id', $id)->exists();
        if (!$ok) abort(404, 'ไม่พบบุคคลนี้');
    }

    public function presentDocument(object $d): array
    {
        return ['id' => (int) $d->id, 'student_id' => (int) $d->student_id, 'kind' => $d->kind, 'label' => $d->label, 'mime' => $d->mime, 'size' => (int) $d->size,
            'review_state' => $d->review_state, 'reviewed_by' => $d->reviewed_by !== null ? (int) $d->reviewed_by : null, 'version' => (int) $d->version, 'created_at' => $d->created_at];
    }

    /** Runs a versioned write and removes ciphertext columns from the 409 body before it reaches the browser. */
    public function guard(\Closure $fn, array $hide): mixed
    {
        try { return $fn(); }
        catch (VersionConflict $e) {
            throw new VersionConflict($e->current === null ? null : array_diff_key($e->current, array_flip($hide)));
        }
    }
}
