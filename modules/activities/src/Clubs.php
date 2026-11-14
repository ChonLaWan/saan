<?php

namespace Saan\Modules\Activities;

use App\Support\Saan\DbError;
use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use App\Support\Saan\TermScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Clubs (ชุมนุม): membership with capacity and waitlist, sessions, attendance and the term result ผ / มผ. */
class Clubs
{
    // ---- lookup and permission -------------------------------------------------------------------

    public function find(int $id): object
    {
        return DB::table('act_clubs')->where('school_id', Common::school())->where('id', $id)->first() ?? abort(404);
    }

    public function canManage(object $club): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        if (Common::isManager($u)) return true;
        return $u->role === 'teacher' && $u->teacher_id && (int) $u->teacher_id === (int) $club->advisor_teacher_id;
    }

    public function requireManage(object $club): void
    {
        abort_unless($this->canManage($club), 403, 'เฉพาะครูที่ปรึกษาหรือผู้ดูแลระบบจัดการชุมนุมนี้ได้');
    }

    /** Clubs of a past term are history: only admin/principal may change them (attendance, results, certificates, enrolment). Same rule as Pbl::writable. */
    public function requireTermOpen(object $club): void
    {
        abort_if(!Common::isManager() && (int) $club->term_id !== (int) TermScope::currentId(), 403, 'ชุมนุมของภาคเรียนที่ผ่านมาแก้ไขได้เฉพาะผู้ดูแลระบบหรือผู้อำนวยการ');
    }

    private function member(int $id): object
    {
        return DB::table('act_members')->where('school_id', Common::school())->where('id', $id)->first() ?? abort(404);
    }

    private function session(int $id): object
    {
        return DB::table('act_sessions')->where('school_id', Common::school())->where('id', $id)->first() ?? abort(404);
    }

    public function memberClub(int $memberId): object
    {
        return $this->find((int) $this->member($memberId)->club_id);
    }

    public function sessionClub(int $sessionId): object
    {
        return $this->find((int) $this->session($sessionId)->club_id);
    }

    // ---- clubs -----------------------------------------------------------------------------------

    public function present(object $c, array $counts = []): array
    {
        return [
            'id' => (int) $c->id, 'term_id' => (int) $c->term_id, 'name' => $c->name, 'advisor_teacher_id' => $c->advisor_teacher_id ? (int) $c->advisor_teacher_id : null,
            'advisor_name' => Common::teacherName($c->advisor_teacher_id ? (int) $c->advisor_teacher_id : null),
            'capacity' => $c->capacity === null ? null : (int) $c->capacity, 'grade_levels' => Common::levels($c->grade_levels), 'description' => (string) ($c->description ?? ''),
            'is_open' => (bool) $c->is_open, 'version' => (int) $c->version, 'can_manage' => $this->canManage($c),
            'enrolled' => (int) ($counts['enrolled'] ?? 0), 'waitlist' => (int) ($counts['waitlist'] ?? 0),
        ];
    }

    public function index(int $termId): array
    {
        $clubs = DB::table('act_clubs')->where('school_id', Common::school())->where('term_id', $termId)->orderBy('name_key')->get();
        $counts = [];
        foreach (DB::table('act_members')->where('school_id', Common::school())->where('term_id', $termId)->whereIn('status', ['enrolled', 'waitlist'])
            ->selectRaw('club_id, status, count(*) as n')->groupBy('club_id', 'status')->get() as $r) $counts[$r->club_id][$r->status] = (int) $r->n;
        return $clubs->map(fn ($c) => $this->present($c, $counts[$c->id] ?? []))->all();
    }

    private function checkAdvisor(?int $teacherId): void
    {
        if ($teacherId === null) return;
        abort_unless(DB::table('teachers')->where('school_id', Common::school())->where('id', $teacherId)->exists(), 422, 'ไม่พบครูที่ปรึกษา');
    }

    private function nameTaken(int $termId, string $key, ?int $exceptId = null): bool
    {
        return DB::table('act_clubs')->where('school_id', Common::school())->where('term_id', $termId)->where('name_key', $key)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
    }

    public function create(array $d): object
    {
        $termId = Common::termId($d['term_id'] ?? null);
        $name = trim((string) preg_replace('/\s+/u', ' ', $d['name']));
        $key = Common::key($name);
        abort_if($key === '', 422, 'กรุณาระบุชื่อชุมนุม');
        abort_if($this->nameTaken($termId, $key), 409, 'มีชุมนุมชื่อนี้ในภาคเรียนนี้แล้ว');
        $advisor = isset($d['advisor_teacher_id']) ? (int) $d['advisor_teacher_id'] : null;
        $this->checkAdvisor($advisor);
        try {
            return Mutation::insert('act_clubs', [
                'term_id' => $termId, 'name' => $name, 'name_key' => $key, 'advisor_teacher_id' => $advisor,
                'capacity' => $d['capacity'] ?? null, 'grade_levels' => Common::normaliseLevels($d['grade_levels'] ?? []),
                'description' => $d['description'] ?? null, 'is_open' => (bool) ($d['is_open'] ?? true),
            ], 'activities.club.create', ['name' => $name, 'term_id' => $termId]);
        } catch (QueryException $e) {
            if (DbError::isUnique($e)) abort(409, 'มีชุมนุมชื่อนี้ในภาคเรียนนี้แล้ว');
            throw $e;
        }
    }

    public function update(int $id, int $version, array $d): object
    {
        $club = $this->find($id);
        $changes = [];
        if (array_key_exists('name', $d)) {
            $name = trim((string) preg_replace('/\s+/u', ' ', $d['name']));
            $key = Common::key($name);
            abort_if($key === '', 422, 'กรุณาระบุชื่อชุมนุม');
            abort_if($this->nameTaken((int) $club->term_id, $key, $id), 409, 'มีชุมนุมชื่อนี้ในภาคเรียนนี้แล้ว');
            $changes += ['name' => $name, 'name_key' => $key];
        }
        if (array_key_exists('advisor_teacher_id', $d)) {
            $a = $d['advisor_teacher_id'] === null ? null : (int) $d['advisor_teacher_id'];
            $this->checkAdvisor($a);
            $changes['advisor_teacher_id'] = $a;
        }
        if (array_key_exists('capacity', $d)) $changes['capacity'] = $d['capacity'];
        if (array_key_exists('grade_levels', $d)) $changes['grade_levels'] = Common::normaliseLevels($d['grade_levels']);
        if (array_key_exists('description', $d)) $changes['description'] = $d['description'];
        if (array_key_exists('is_open', $d)) $changes['is_open'] = (bool) $d['is_open'];
        try {
            return DB::transaction(function () use ($id, $version, $changes) {
                DB::table('act_clubs')->where('school_id', Common::school())->where('id', $id)->lockForUpdate()->first();
                $row = Mutation::update('act_clubs', $id, $version, $changes, 'activities.club.update', array_diff_key($changes, ['name_key' => 1]));
                if (array_key_exists('capacity', $changes)) $this->promote($row); // a larger capacity pulls people off the waitlist
                return $row;
            });
        } catch (QueryException $e) {
            if (DbError::isUnique($e)) abort(409, 'มีชุมนุมชื่อนี้ในภาคเรียนนี้แล้ว');
            throw $e;
        }
    }

    public function destroy(int $id, int $version): void
    {
        $club = $this->find($id);
        DB::transaction(function () use ($club, $version) {
            DB::table('act_clubs')->where('id', $club->id)->lockForUpdate()->first();
            $busy = DB::table('act_members')->where('club_id', $club->id)->exists() || DB::table('act_sessions')->where('club_id', $club->id)->exists()
                || DB::table('act_certificates')->where('club_id', $club->id)->exists();
            abort_if($busy, 422, 'ลบไม่ได้ เพราะชุมนุมนี้มีสมาชิก คาบกิจกรรม หรือเกียรติบัตรแล้ว (ปิดรับสมัครแทนได้)');
            Mutation::delete('act_clubs', (int) $club->id, $version, 'activities.club.delete');
        });
    }

    // ---- attendance scoring ----------------------------------------------------------------------

    /** Sessions that count toward ผ/มผ: already held (date <= today) and with a roll call. id => Y-m-d. */
    private function countedSessions(int $clubId): array
    {
        $out = [];
        foreach (DB::table('act_sessions')->where('club_id', $clubId)->where('date', '<=', Common::today())
            ->whereExists(fn ($q) => $q->from('act_attendance as a')->whereColumn('a.session_id', 'act_sessions.id'))->orderBy('date')->get(['id', 'date']) as $r) {
            $out[(int) $r->id] = substr((string) $r->date, 0, 10);
        }
        return $out;
    }

    /** student_id => [session_id => true] for present marks in the given sessions. */
    private function presenceMap(array $sessionIds): array
    {
        $map = [];
        if (!$sessionIds) return $map;
        foreach (array_chunk($sessionIds, 500) as $chunk) {
            foreach (DB::table('act_attendance')->whereIn('session_id', $chunk)->where('present', 1)->get(['student_id', 'session_id']) as $r) $map[(int) $r->student_id][(int) $r->session_id] = true;
        }
        return $map;
    }

    /** [present, total] for one member: only sessions on or after the day the member got a seat (null = all). */
    private function score(object $m, array $counted, array $presence): array
    {
        $from = !empty($m->enrolled_on) ? substr((string) $m->enrolled_on, 0, 10) : null;
        $n = 0;
        $total = 0;
        foreach ($counted as $sid => $date) {
            if ($from !== null && $date < $from) continue;
            $total++;
            if (isset($presence[(int) $m->student_id][$sid])) $n++;
        }
        return [$n, $total];
    }

    // ---- members ---------------------------------------------------------------------------------

    private function enrolledCount(int $clubId): int
    {
        return (int) DB::table('act_members')->where('club_id', $clubId)->where('status', 'enrolled')->count();
    }

    /** Move waitlisted members (oldest first) into free seats. Caller holds the club lock. */
    private function promote(object $club): int
    {
        if ($club->capacity === null) $free = PHP_INT_MAX; else $free = (int) $club->capacity - $this->enrolledCount((int) $club->id);
        $n = 0;
        if ($free <= 0) return 0;
        foreach (DB::table('act_members')->where('club_id', $club->id)->where('status', 'waitlist')->orderBy('id')->limit(min($free, 5000))->get() as $m) {
            Mutation::update('act_members', (int) $m->id, (int) $m->version, ['status' => 'enrolled', 'enrolled_on' => Common::today()], 'activities.member.promote', ['student_id' => (int) $m->student_id]);
            $n++;
        }
        return $n;
    }

    /**
     * Bulk enrolment. Returns ['enrolled' => n, 'waitlisted' => n, 'rejected' => [['student_id','reason']]]. Never partial per student:
     * each student is either added (enrolled or waitlisted) or rejected with a reason.
     */
    public function enroll(int $clubId, array $studentIds): array
    {
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
        return DB::transaction(function () use ($clubId, $studentIds) {
            $club = DB::table('act_clubs')->where('school_id', Common::school())->where('id', $clubId)->lockForUpdate()->first() ?? abort(404);
            abort_unless($this->canManage($club) || auth()->user()?->role === 'teacher', 403, 'ไม่มีสิทธิ์ลงทะเบียนชุมนุม');
            $this->requireTermOpen($club);
            abort_unless((bool) $club->is_open, 422, 'ชุมนุมนี้ปิดรับสมัครแล้ว');
            $manage = $this->canManage($club);
            $levels = Common::levels($club->grade_levels);
            $students = Common::studentsQuery()->whereIn('s.id', $studentIds ?: [0])->get()->keyBy('id');
            $late = $this->countedSessions((int) $club->id) !== [];
            $out = ['enrolled' => 0, 'waitlisted' => 0, 'rejected' => []];
            $reject = function (int $id, string $why) use (&$out) { $out['rejected'][] = ['student_id' => $id, 'reason' => $why]; };
            $seats = $club->capacity === null ? PHP_INT_MAX : (int) $club->capacity - $this->enrolledCount((int) $club->id);
            foreach ($studentIds as $sid) {
                $s = $students->get($sid);
                if (!$s) { $reject($sid, 'ไม่พบนักเรียน หรือไม่ได้อยู่ในสถานะกำลังศึกษา'); continue; }
                if (!$manage && !Common::canStudent($s)) { $reject($sid, 'ไม่มีสิทธิ์จัดการนักเรียนห้องนี้'); continue; }
                if (!Common::levelAllowed($levels, Common::gradeOf($s))) { $reject($sid, 'ระดับชั้นไม่ตรงกับที่ชุมนุมรับ'); continue; }
                $active = DB::table('act_members')->where('term_id', $club->term_id)->where('student_id', $sid)->whereNotNull('active_key')->first();
                if ($active) { $reject($sid, (int) $active->club_id === (int) $club->id ? 'อยู่ในชุมนุมนี้แล้ว' : 'อยู่ในชุมนุมอื่นของภาคเรียนนี้แล้ว'); continue; }
                $status = $seats > 0 ? 'enrolled' : 'waitlist';
                // a seat taken after roll calls already exist only counts sessions from today on
                $values = ['status' => $status, 'active_key' => $club->term_id.':'.$sid, 'result' => null, 'result_manual' => false, 'enrolled_on' => ($status === 'enrolled' && $late) ? Common::today() : null];
                try {
                    $old = DB::table('act_members')->where('club_id', $club->id)->where('student_id', $sid)->first();
                    // a result the advisor set by hand survives a leave and re-enrol: nobody else can wipe it by cycling the student out and back in
                    if ($old && $old->result_manual) $values = array_diff_key($values, ['result' => 1, 'result_manual' => 1]);
                    DB::transaction(function () use ($old, $club, $sid, $values) {
                        if ($old) Mutation::update('act_members', (int) $old->id, (int) $old->version, $values, 'activities.member.enroll', ['student_id' => $sid, 'status' => $values['status']]);
                        else Mutation::insert('act_members', $values + ['club_id' => (int) $club->id, 'term_id' => (int) $club->term_id, 'student_id' => $sid], 'activities.member.enroll', ['student_id' => $sid, 'status' => $values['status']]);
                    });
                } catch (QueryException $e) {
                    if (DbError::isUnique($e)) { $reject($sid, 'อยู่ในชุมนุมของภาคเรียนนี้แล้ว'); continue; }
                    throw $e;
                }
                if ($status === 'enrolled') { $out['enrolled']++; $seats--; } else $out['waitlisted']++;
            }
            Mutation::audit('activities.club.enroll', 'act_clubs', (int) $club->id, ['requested' => count($studentIds), 'enrolled' => $out['enrolled'], 'waitlisted' => $out['waitlisted'], 'rejected' => count($out['rejected'])]);
            return $out;
        });
    }

    public function leave(int $memberId, int $version): object
    {
        $m = $this->member($memberId);
        return DB::transaction(function () use ($m, $version) {
            $club = DB::table('act_clubs')->where('id', $m->club_id)->lockForUpdate()->first() ?? abort(404);
            $this->requireTermOpen($club);
            $student = Common::studentsQuery()->where('s.id', $m->student_id)->first();
            abort_unless($this->canManage($club) || ($student && Common::canStudent($student)), 403, 'ไม่มีสิทธิ์จัดการสมาชิกคนนี้');
            abort_if($m->status === 'left', 422, 'สมาชิกคนนี้ออกจากชุมนุมแล้ว');
            abort_if($m->result_manual && !$this->canManage($club), 403, 'สมาชิกคนนี้มีผลการประเมินที่ครูที่ปรึกษาบันทึกไว้ เฉพาะครูที่ปรึกษาชุมนุมหรือผู้ดูแลระบบเท่านั้นที่ให้ออกได้');
            $row = Mutation::update('act_members', (int) $m->id, $version, ['status' => 'left', 'active_key' => null], 'activities.member.leave', ['student_id' => (int) $m->student_id, 'was' => $m->status]);
            if ($m->status === 'enrolled') $this->promote($club);
            return $row;
        });
    }

    // ---- detail ----------------------------------------------------------------------------------

    public function detail(object $club): array
    {
        $pass = Common::passPercent();
        $sessions = DB::table('act_sessions')->where('club_id', $club->id)->orderBy('date')->get();
        $counted = $this->countedSessions((int) $club->id);
        $presence = $this->presenceMap(array_keys($counted));
        $certs = DB::table('act_certificates')->where('club_id', $club->id)->pluck('number', 'member_id');
        $members = [];
        foreach (DB::table('act_members as m')->where('m.school_id', Common::school())->where('m.club_id', $club->id)->whereIn('m.status', ['enrolled', 'waitlist'])
            ->leftJoin('students as s', 's.id', '=', 'm.student_id')->leftJoin('classrooms as c', 'c.id', '=', 's.classroom_id')
            ->orderBy('m.status')->orderBy('m.id')->select('m.id', 'm.student_id', 'm.status', 'm.result', 'm.result_manual', 'm.version', 'm.enrolled_on', 's.name', 's.code', 'c.name as classroom_name')->get() as $m) {
            [$n, $total] = $this->score($m, $counted, $presence);
            $members[] = ['id' => (int) $m->id, 'student_id' => (int) $m->student_id, 'name' => $m->name, 'code' => $m->code, 'classroom_name' => $m->classroom_name, 'status' => $m->status,
                'result' => $m->result, 'result_manual' => (bool) $m->result_manual, 'version' => (int) $m->version, 'present' => $n, 'sessions' => $total,
                'percent' => $total > 0 ? round($n * 100 / $total, 1) : null, 'certificate_no' => $certs[$m->id] ?? null];
        }
        return ['club' => $this->present($club, ['enrolled' => count(array_filter($members, fn ($x) => $x['status'] === 'enrolled')), 'waitlist' => count(array_filter($members, fn ($x) => $x['status'] === 'waitlist'))]),
            'members' => $members, 'pass_percent' => $pass,
            'sessions' => $sessions->map(fn ($s) => ['id' => (int) $s->id, 'date' => (string) $s->date, 'topic' => (string) ($s->topic ?? ''), 'version' => (int) $s->version])->all()];
    }

    // ---- sessions and attendance -----------------------------------------------------------------

    /** A session must fall inside the club's term when that term has dates (academic_terms.starts_on / ends_on). */
    private function requireInTerm(object $club, string $date): void
    {
        if (!Schema::hasColumn('academic_terms', 'starts_on') || !Schema::hasColumn('academic_terms', 'ends_on')) return;
        $t = DB::table('academic_terms')->where('school_id', Common::school())->where('id', $club->term_id)->first();
        if (!$t) return;
        $from = $t->starts_on ? substr((string) $t->starts_on, 0, 10) : null;
        $to = $t->ends_on ? substr((string) $t->ends_on, 0, 10) : null;
        abort_if(($from !== null && $date < $from) || ($to !== null && $date > $to), 422, 'วันที่ไม่อยู่ในภาคเรียนของชุมนุมนี้');
    }

    public function sessionCreate(int $clubId, string $date, ?string $topic): object
    {
        $club = $this->find($clubId);
        $this->requireTermOpen($club);
        $this->requireInTerm($club, $date);
        abort_if(DB::table('act_sessions')->where('club_id', $clubId)->where('date', $date)->exists(), 409, 'มีคาบกิจกรรมวันนี้อยู่แล้ว');
        try {
            return Mutation::insert('act_sessions', ['club_id' => (int) $club->id, 'date' => $date, 'topic' => $topic], 'activities.session.create', ['club_id' => (int) $club->id, 'date' => $date]);
        } catch (QueryException $e) {
            if (DbError::isUnique($e)) abort(409, 'มีคาบกิจกรรมวันนี้อยู่แล้ว');
            throw $e;
        }
    }

    public function sessionUpdate(int $id, int $version, array $d): object
    {
        $s = $this->session($id);
        $this->requireTermOpen($this->find((int) $s->club_id));
        $changes = [];
        if (isset($d['date']) && $d['date'] !== substr((string) $s->date, 0, 10)) {
            $this->requireInTerm($this->find((int) $s->club_id), $d['date']);
            abort_if(DB::table('act_sessions')->where('club_id', $s->club_id)->where('date', $d['date'])->where('id', '!=', $id)->exists(), 409, 'มีคาบกิจกรรมวันนี้อยู่แล้ว');
            $changes['date'] = $d['date'];
        }
        if (array_key_exists('topic', $d)) $changes['topic'] = $d['topic'];
        try {
            return Mutation::update('act_sessions', $id, $version, $changes, 'activities.session.update', $changes);
        } catch (QueryException $e) {
            if (DbError::isUnique($e)) abort(409, 'มีคาบกิจกรรมวันนี้อยู่แล้ว');
            throw $e;
        }
    }

    public function sessionDestroy(int $id, int $version): void
    {
        $s = $this->session($id);
        $this->requireTermOpen($this->find((int) $s->club_id));
        DB::transaction(function () use ($id, $version) {
            Mutation::delete('act_sessions', $id, $version, 'activities.session.delete');
            DB::table('act_attendance')->where('school_id', Common::school())->where('session_id', $id)->delete();
        });
    }

    public function attendance(int $sessionId): array
    {
        $s = $this->session($sessionId);
        $marks = DB::table('act_attendance')->where('session_id', $sessionId)->pluck('present', 'student_id');
        $roster = DB::table('act_members as m')->join('students as s', 's.id', '=', 'm.student_id')->where('m.club_id', $s->club_id)->where('m.status', 'enrolled')
            ->orderBy('s.code')->select('m.student_id', 's.name', 's.code')->get()
            ->map(fn ($r) => ['student_id' => (int) $r->student_id, 'name' => $r->name, 'code' => $r->code, 'present' => $marks->has($r->student_id) ? (bool) $marks[$r->student_id] : null])->all();
        return ['session' => ['id' => (int) $s->id, 'club_id' => (int) $s->club_id, 'date' => (string) $s->date, 'topic' => (string) ($s->topic ?? ''), 'version' => (int) $s->version], 'roster' => $roster];
    }

    public function saveAttendance(int $sessionId, int $version, array $records): array
    {
        $s = $this->session($sessionId);
        $this->requireTermOpen($this->find((int) $s->club_id));
        abort_if((string) $s->date > Common::today(), 422, 'เช็คชื่อล่วงหน้าไม่ได้ คาบนี้ยังมาไม่ถึง');
        $enrolled = DB::table('act_members')->where('club_id', $s->club_id)->where('status', 'enrolled')->pluck('student_id')->map(fn ($v) => (int) $v)->all();
        $map = [];
        foreach ($records as $r) {
            $sid = (int) $r['student_id'];
            abort_unless(in_array($sid, $enrolled, true), 422, 'มีนักเรียนที่ไม่ได้เป็นสมาชิกของชุมนุมนี้');
            $map[$sid] = (bool) $r['present'];
        }
        return DB::transaction(function () use ($s, $version, $map) {
            // version check first: a stale screen must not overwrite a newer roll call
            $row = Mutation::update('act_sessions', (int) $s->id, $version, [], 'activities.attendance.save', ['club_id' => (int) $s->club_id, 'date' => (string) $s->date, 'marked' => count($map), 'present' => count(array_filter($map))]);
            foreach ($map as $sid => $present) {
                $n = DB::table('act_attendance')->where('session_id', $s->id)->where('student_id', $sid)->update(['present' => $present]);
                if ($n === 0) DB::table('act_attendance')->insert(['school_id' => Common::school(), 'session_id' => (int) $s->id, 'student_id' => $sid, 'present' => $present]);
            }
            return ['version' => (int) $row->version, 'marked' => count($map)];
        });
    }

    // ---- results ---------------------------------------------------------------------------------

    /** Derive ผ / มผ from attendance for every enrolled member without a manual override. */
    public function computeResults(int $clubId): array
    {
        $club = $this->find($clubId);
        $this->requireTermOpen($club);
        return DB::transaction(function () use ($club) {
            DB::table('act_clubs')->where('id', $club->id)->lockForUpdate()->first();
            $pass = Common::passPercent();
            $counted = $this->countedSessions((int) $club->id);
            $presence = $this->presenceMap(array_keys($counted));
            $out = ['pass_percent' => $pass, 'sessions' => count($counted), 'passed' => 0, 'failed' => 0, 'manual_kept' => 0, 'unchanged' => 0];
            if (!$counted) return $out + ['note' => 'ยังไม่มีคาบกิจกรรมที่จัดแล้วและเช็คชื่อแล้ว จึงยังสรุปผลไม่ได้'];
            foreach (DB::table('act_members')->where('club_id', $club->id)->where('status', 'enrolled')->orderBy('id')->get() as $m) {
                if ($m->result_manual) { $out['manual_kept']++; continue; }
                [$n, $total] = $this->score($m, $counted, $presence);
                if ($total === 0) { $out['unchanged']++; continue; } // seat taken after every counted session
                $result = $n * 100 >= $pass * $total ? 'ผ' : 'มผ';
                $out[$result === 'ผ' ? 'passed' : 'failed']++;
                if ($m->result === $result) { $out['unchanged']++; continue; }
                Mutation::update('act_members', (int) $m->id, (int) $m->version, ['result' => $result], 'activities.result.compute', ['student_id' => (int) $m->student_id, 'result' => $result, 'pass_percent' => $pass]);
            }
            Mutation::audit('activities.club.results', 'act_clubs', (int) $club->id, $out);
            return $out;
        });
    }

    /** Manual override (audited with a reason). result = null drops the override and recomputes from attendance. */
    public function overrideResult(int $memberId, int $version, ?string $result, string $reason): object
    {
        $m = $this->member($memberId);
        $club = $this->find((int) $m->club_id);
        $this->requireManage($club);
        $this->requireTermOpen($club);
        abort_unless($m->status === 'enrolled', 422, 'บันทึกผลได้เฉพาะสมาชิกที่ได้รับที่นั่งแล้ว');
        return DB::transaction(function () use ($m, $version, $result, $reason) {
            $detail = ['student_id' => (int) $m->student_id, 'from' => $m->result, 'to' => $result, 'reason' => $reason, 'was_manual' => (bool) $m->result_manual];
            if ($result !== null) return Mutation::update('act_members', (int) $m->id, $version, ['result' => $result, 'result_manual' => true], 'activities.result.override', $detail);
            $counted = $this->countedSessions((int) $m->club_id);
            [$n, $total] = $this->score($m, $counted, $this->presenceMap(array_keys($counted)));
            $auto = $total > 0 ? ($n * 100 >= Common::passPercent() * $total ? 'ผ' : 'มผ') : null;
            $detail['to'] = $auto;
            return Mutation::update('act_members', (int) $m->id, $version, ['result' => $auto, 'result_manual' => false], 'activities.result.override_clear', $detail);
        });
    }

    // ---- settings --------------------------------------------------------------------------------

    public function settings(): array
    {
        return ['pass_percent' => Common::passPercent(), 'cert_prefix' => (string) Settings::get('activities.cert_prefix', ''),
            'signer_name' => (string) Settings::get('activities.signer_name', ''), 'signer_title' => (string) Settings::get('activities.signer_title', '')];
    }

    public function saveSettings(array $d): array
    {
        $before = $this->settings();
        if (array_key_exists('cert_prefix', $d)) {
            $p = strtoupper(trim((string) $d['cert_prefix']));
            abort_if($p !== '' && $p === Certificates::surveysCode(), 422, 'อักษรนำนี้ใช้กับเกียรติบัตรแบบสำรวจแล้ว กรุณาใช้อักษรอื่น');
        }
        foreach (['pass_percent' => Common::PASS_KEY, 'cert_prefix' => 'activities.cert_prefix', 'signer_name' => 'activities.signer_name', 'signer_title' => 'activities.signer_title'] as $field => $key) {
            if (array_key_exists($field, $d)) Settings::put($key, $field === 'pass_percent' ? (int) $d[$field] : trim((string) $d[$field]));
        }
        $after = $this->settings();
        Mutation::audit('activities.settings.update', 'saan_settings', null, ['before' => $before, 'after' => $after]);
        return $after;
    }
}
