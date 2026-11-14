<?php

namespace Saan\Modules\StudentPortal\Http;

use App\Support\Saan\Mutation;
use App\Support\Saan\TeacherScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Saan\Modules\GuardianPortal\Access as GAccess;
use Saan\Modules\GuardianPortal\Contacts;
use Saan\Modules\GuardianPortal\Links;
use Saan\Modules\GuardianPortal\SecretPolicy;
use Saan\Modules\StudentPortal\Access;
use Saan\Modules\StudentPortal\Credentials;

/**
 * Staff side of the student portal (work page #student-accounts): status of every student account and of the guardian accounts without e-mail,
 * issue / reset / unlock, and the secrets for the printable slip sheet. Admin/principal: every classroom. Teacher: homeroom classrooms only
 * (TeacherScope::homeroomIds), checked on every classroom and every student/guardian id. Secrets are returned once in the response of the
 * action (Cache-Control: no-store, never Idempotency-cached) and are stored only as hashes; audit details never carry them.
 */
class StaffController
{
    private const MAX_BATCH = 80;

    private function user(): object
    {
        return auth()->user();
    }

    private function classroom(int $id): object
    {
        $room = DB::table('classrooms')->where('school_id', Access::school())->where('id', $id)->first(['id', 'name']);
        if (!$room) abort(404, 'ไม่พบห้องเรียน');
        $ids = TeacherScope::homeroomIds($this->user());
        if ($ids !== null && !in_array($id, $ids, true)) abort(403, 'จัดการรหัสได้เฉพาะห้องประจำชั้นของตนเอง');
        return $room;
    }

    /** Studying student of the teacher's homeroom (or any, for admin/principal) or 404/403/422. */
    private function student(int $id): object
    {
        $s = Links::scopedStudent($id, $this->user());
        if (($s->status ?? 'studying') !== 'studying') abort(422, 'นักเรียนไม่ได้อยู่ในสถานะกำลังศึกษา');
        return $s;
    }

    private function students(int $classroomId)
    {
        $roll = \Illuminate\Support\Facades\Schema::hasColumn('students', 'roll_number');
        $q = DB::table('students')->where('school_id', Access::school())->where('classroom_id', $classroomId)->where('status', 'studying');
        if ($roll) $q->orderByRaw('roll_number is null')->orderBy('roll_number');
        return $q->orderBy('code')->get(['id', 'code', 'name', 'first_name', 'last_name'] + ($roll ? [5 => 'roll_number'] : []));
    }

    private function name(object $s): string
    {
        $full = trim(trim((string) ($s->first_name ?? '')).' '.trim((string) ($s->last_name ?? '')));
        return $full !== '' ? $full : (string) $s->name;
    }

    private function noStore(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    public function context(): JsonResponse
    {
        $ids = TeacherScope::homeroomIds($this->user());
        $q = DB::table('classrooms')->where('school_id', Access::school())->orderBy('name');
        if ($ids !== null) $q->whereIn('id', $ids ?: [0]);
        return response()->json(['classrooms' => $q->get(['id', 'name'])->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->all(),
            'password_min' => SecretPolicy::min(), 'is_manager' => in_array($this->user()->role, TeacherScope::ALL_ROLES, true)]);
    }

    public function accounts(int $id): JsonResponse
    {
        $room = $this->classroom($id);
        $students = $this->students($id);
        $sids = $students->pluck('id')->map(fn ($v) => (int) $v)->all();
        $pins = DB::table('api_student_pins')->where('school_id', Access::school())->whereIn('student_id', $sids ?: [0])->get()->keyBy('student_id');
        $mail = Contacts::verifiedMap('student', $sids);
        $now = time();
        // guardians of these students and their accounts (an account counts for a student only through a used, unrevoked invite = the teacher vouched for it)
        $rel = DB::table('reg_student_guardians as sg')->join('reg_guardians as g', 'g.id', '=', 'sg.guardian_id')->where('sg.school_id', Access::school())->whereIn('sg.student_id', $sids ?: [0])
            ->orderBy('sg.id')->get(['sg.student_id', 'sg.guardian_id', 'sg.relationship', 'sg.can_pick_up', 'g.display_name']);
        $gids = $rel->pluck('guardian_id')->unique()->map(fn ($v) => (int) $v)->all();
        $accs = DB::table('gp_accounts')->where('school_id', Access::school())->whereIn('guardian_id', $gids ?: [0])->get()->keyBy('guardian_id');
        $attached = DB::table('gp_links')->where('school_id', Access::school())->where('purpose', 'invite')->whereNotNull('used_at')->whereNull('revoked_at')
            ->whereIn('student_id', $sids ?: [0])->get(['student_id', 'guardian_id'])->map(fn ($l) => $l->student_id.'-'.$l->guardian_id)->flip()->all();
        $gmail = Contacts::verifiedMap('guardian', $gids);
        $guardians = [];
        foreach ($rel as $g) {
            $a = $accs->get($g->guardian_id);
            $guardians[(int) $g->student_id][] = ['guardian_id' => (int) $g->guardian_id, 'name' => $g->display_name, 'relationship' => $g->relationship,
                'may_view' => (bool) $g->can_pick_up || GAccess::restrictedCanView(),
                'has_account' => (bool) $a, 'attached' => isset($attached[$g->student_id.'-'.$g->guardian_id]), 'username' => $a?->username, 'active' => $a ? (bool) $a->is_active : null,
                'locked' => $a && $a->locked_until !== null && strtotime((string) $a->locked_until) > $now, 'temporary' => $a ? (bool) $a->must_change : null,
                'has_verified_email' => isset($gmail[(int) $g->guardian_id]), 'last_login_at' => $a?->last_login_at];
        }
        $data = $students->map(function ($s) use ($pins, $mail, $now, $guardians) {
            $p = $pins->get($s->id);
            return ['id' => (int) $s->id, 'code' => $s->code, 'name' => $this->name($s), 'roll' => isset($s->roll_number) ? (int) $s->roll_number : null,
                'has_secret' => (bool) $p, 'locked' => $p && $p->locked_until !== null && strtotime((string) $p->locked_until) > $now, 'temporary' => $p ? (bool) $p->must_change : null,
                'has_verified_email' => isset($mail[(int) $s->id]), 'last_login_at' => $p?->last_login_at, 'issued_at' => $p?->issued_at, 'guardians' => $guardians[(int) $s->id] ?? []];
        })->all();
        return $this->noStore(['classroom' => ['id' => (int) $room->id, 'name' => $room->name], 'data' => $data]);
    }

    /** Issue temporary secrets for a classroom: students without a secret (default), or the listed ids / everybody when mode=all. Returns the slip rows once. */
    public function issueClass(Request $r, int $id): JsonResponse
    {
        $room = $this->classroom($id);
        $d = $r->validate(['mode' => ['sometimes', 'in:missing,all'], 'student_ids' => ['sometimes', 'array', 'max:'.self::MAX_BATCH], 'student_ids.*' => ['integer', 'min:1']]);
        $students = $this->students($id);
        if (isset($d['student_ids'])) $students = $students->filter(fn ($s) => in_array((int) $s->id, array_map('intval', $d['student_ids']), true))->values();
        if (($d['mode'] ?? 'missing') === 'missing') {
            $have = DB::table('api_student_pins')->where('school_id', Access::school())->whereIn('student_id', $students->pluck('id')->all() ?: [0])->pluck('student_id')->map(fn ($v) => (int) $v)->all();
            $students = $students->reject(fn ($s) => in_array((int) $s->id, $have, true))->values();
        }
        if ($students->count() > self::MAX_BATCH) abort(422, 'ออกรหัสได้ครั้งละไม่เกิน '.self::MAX_BATCH.' คน');
        $slips = DB::transaction(fn () => $students->map(fn ($s) => ['kind' => 'student', 'student_id' => (int) $s->id, 'code' => (string) $s->code, 'name' => $this->name($s), 'secret' => Credentials::issue($s)])->all());
        return $this->noStore(['classroom' => ['id' => (int) $room->id, 'name' => $room->name], 'data' => $slips]);
    }

    public function reset(int $id): JsonResponse
    {
        $s = $this->student($id);
        $room = DB::table('classrooms')->where('school_id', Access::school())->where('id', $s->classroom_id)->value('name');
        $secret = Credentials::issue($s);
        return $this->noStore(['classroom' => ['id' => (int) $s->classroom_id, 'name' => $room], 'data' => [['kind' => 'student', 'student_id' => (int) $s->id, 'code' => (string) $s->code, 'name' => $this->name($s), 'secret' => $secret]]]);
    }

    public function unlock(int $id): JsonResponse
    {
        Credentials::unlock($this->student($id));
        return response()->json(['ok' => true]);
    }

    // ---- guardians without e-mail: username + password issued by the homeroom teacher ----

    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    private function tempPassword(): string
    {
        $s = '';
        for ($i = 0; $i < 8; $i++) $s .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        return $s;
    }

    private function newUsername(): string
    {
        do {
            $u = 'g'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (DB::table('gp_accounts')->where('school_id', Access::school())->where('username_key', $u)->exists());
        return $u;
    }

    /** Guardian the staff member may manage through this student: linked in the registry and allowed to view. */
    public function issueGuardian(Request $r, int $studentId, int $guardianId): JsonResponse
    {
        $student = $this->student($studentId);
        $rel = DB::table('reg_student_guardians')->where('school_id', Access::school())->where('student_id', $studentId)->where('guardian_id', $guardianId)->first(['id', 'can_pick_up']);
        if (!$rel) abort(404, 'ผู้ปกครองรายนี้ไม่ได้ผูกกับนักเรียน');
        if (!$rel->can_pick_up && !GAccess::restrictedCanView()) abort(422, 'ผู้ปกครองรายนี้ถูกจำกัดสิทธิ์ โรงเรียนไม่อนุญาตให้ดูข้อมูลนักเรียน');
        $name = (string) DB::table('reg_guardians')->where('school_id', Access::school())->where('id', $guardianId)->value('display_name');
        $secret = $this->tempPassword();
        $username = DB::transaction(function () use ($student, $studentId, $guardianId, $secret) {
            DB::table('reg_student_guardians')->where('school_id', Access::school())->where('student_id', $studentId)->where('guardian_id', $guardianId)->lockForUpdate()->get(['id']);
            $acc = DB::table('gp_accounts')->where('school_id', Access::school())->where('guardian_id', $guardianId)->lockForUpdate()->first();
            if ($acc) {
                if (!$acc->is_active) abort(422, 'บัญชีผู้ปกครองรายนี้ถูกปิดใช้งาน ต้องให้ผู้ดูแลระบบเปิดใช้งานอีกครั้ง');
                // a teacher may reset only an account whose every visible child is in the teacher's own homeroom (else a teacher could take over a guardian who also sees other classrooms)
                $rooms = TeacherScope::homeroomIds($this->user());
                if ($rooms !== null) {
                    $others = DB::table('gp_links as l')->join('students as s', 's.id', '=', 'l.student_id')->where('l.school_id', Access::school())->where('l.guardian_id', $guardianId)
                        ->where('l.purpose', 'invite')->whereNotNull('l.used_at')->whereNull('l.revoked_at')->where('s.status', 'studying')
                        ->where(fn ($w) => $w->whereNull('s.classroom_id')->orWhereNotIn('s.classroom_id', $rooms ?: [0]))->exists(); // a child without a classroom is not the teacher's homeroom either (NOT IN alone is NULL for it)
                    if ($others) abort(403, 'บัญชีนี้ดูแลนักเรียนนอกห้องประจำชั้นของคุณ ให้ผู้ดูแลระบบหรือผู้อำนวยการเป็นผู้รีเซ็ต');
                }
                Contacts::revoke('guardian', $guardianId); // an e-mail bound before this reset may belong to whoever held the old session
                $username = $acc->username ?: $this->newUsername();
                Mutation::update('gp_accounts', (int) $acc->id, (int) $acc->version, ['username' => $username, 'username_key' => mb_strtolower($username), 'password' => Hash::make($secret), 'must_change' => true,
                    'failed_count' => 0, 'lock_count' => 0, 'locked_until' => null, 'auth_version' => DB::raw('auth_version + 1')], 'student_portal.guardian.reset', ['guardian_id' => $guardianId, 'student_id' => $studentId]);
            } else {
                $username = $this->newUsername();
                Mutation::insert('gp_accounts', ['guardian_id' => $guardianId, 'username' => $username, 'username_key' => $username, 'password' => Hash::make($secret), 'is_active' => true,
                    'must_change' => true, 'auth_version' => 1], 'student_portal.guardian.issued', ['guardian_id' => $guardianId, 'student_id' => $studentId]);
            }
            $attached = DB::table('gp_links')->where('school_id', Access::school())->where('guardian_id', $guardianId)->where('student_id', $studentId)->where('purpose', 'invite')
                ->whereNotNull('used_at')->whereNull('revoked_at')->exists();
            if (!$attached) {
                // the teacher vouches for this guardian + child: an already used invite row is what GuardianPortal\Access reads as "may see this child"
                Mutation::insert('gp_links', ['guardian_id' => $guardianId, 'student_id' => $studentId, 'token_hash' => hash('sha256', random_bytes(24)), 'purpose' => 'invite',
                    'expires_at' => now()->toDateTimeString(), 'used_at' => now(), 'created_by' => (int) auth()->id()], 'student_portal.guardian.attached', ['guardian_id' => $guardianId, 'student_id' => $studentId]);
            }
            return $username;
        });
        $room = DB::table('classrooms')->where('school_id', Access::school())->where('id', $student->classroom_id)->value('name');
        return $this->noStore(['classroom' => ['id' => (int) $student->classroom_id, 'name' => $room], 'data' => [['kind' => 'guardian', 'guardian_id' => $guardianId, 'name' => $name,
            'student_name' => $this->name($student), 'code' => $username, 'secret' => $secret]]]);
    }

    public function unlockGuardian(int $guardianId): JsonResponse
    {
        $acc = DB::table('gp_accounts')->where('school_id', Access::school())->where('guardian_id', $guardianId)->first();
        if (!$acc) abort(404, 'ไม่พบบัญชีผู้ปกครอง');
        $rooms = TeacherScope::homeroomIds($this->user());
        if ($rooms !== null) {
            $own = DB::table('reg_student_guardians as sg')->join('students as s', 's.id', '=', 'sg.student_id')->where('sg.school_id', Access::school())->where('s.school_id', Access::school())
                ->where('sg.guardian_id', $guardianId)->where('s.status', 'studying')->whereIn('s.classroom_id', $rooms ?: [0])->exists();
            if (!$own) abort(403, 'ผู้ปกครองรายนี้ไม่ได้ผูกกับนักเรียนในห้องประจำชั้นของคุณ');
            if (Links::hasChildrenOutside($guardianId, $rooms)) abort(403, 'บัญชีนี้ดูแลนักเรียนนอกห้องประจำชั้นของคุณ ให้ผู้ดูแลระบบหรือผู้อำนวยการเป็นผู้ดำเนินการ');
        }
        Mutation::update('gp_accounts', (int) $acc->id, (int) $acc->version, ['failed_count' => 0, 'lock_count' => 0, 'locked_until' => null], 'student_portal.guardian.unlocked', ['guardian_id' => $guardianId]);
        return response()->json(['ok' => true]);
    }
}
