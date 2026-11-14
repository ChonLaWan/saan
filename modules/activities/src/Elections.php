<?php

namespace Saan\Modules\Activities;

use App\Support\Saan\DbError;
use App\Support\Saan\Mutation;
use App\Support\Saan\TeacherScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Student-council election with a secret ballot.
 * - act_ballots holds the choice, act_voters holds who voted. The two tables share no student id, no sequential id and no timestamp finer than a day.
 * - voter_key is a random value (never derived from the student or any key). A second vote is blocked by act_voters unique(election, student), inserted in the same
 *   transaction as the ballot. No secret is stored, so no DB copy, backup or dump can re-link a ballot to a student.
 * - A teacher unlocks the kiosk for one student at a time (unlock() returns a single-use ticket); the vote request carries the ticket, not the student.
 */
class Elections
{
    public const TICKET_SECONDS = 300;

    public function find(int $id): object
    {
        $e = DB::table('act_elections')->where('school_id', Common::school())->where('id', $id)->first() ?? abort(404);
        return $this->autoClose($e);
    }

    /** Past closes_at nobody may have pressed close: do it now (lazy, on first read). */
    private function autoClose(object $e): object
    {
        if ($e->status !== 'open' || Common::at($e->closes_at)->gt(now())) return $e;
        return DB::transaction(function () use ($e) {
            $n = DB::table('act_elections')->where('school_id', Common::school())->where('id', $e->id)->where('status', 'open')->where('closes_at', '<=', now())
                ->update(['status' => 'closed', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            if ($n > 0) Mutation::audit('activities.election.autoclose', 'act_elections', (int) $e->id, ['title' => $e->title]);
            return DB::table('act_elections')->where('school_id', Common::school())->where('id', $e->id)->first();
        });
    }

    public function present(object $e): array
    {
        return ['id' => (int) $e->id, 'title' => $e->title, 'opens_at' => Common::iso($e->opens_at), 'closes_at' => Common::iso($e->closes_at),
            'eligible_grade_levels' => Common::levels($e->eligible_grade_levels), 'status' => $e->status, 'version' => (int) $e->version,
            'voting_now' => $this->votingOpen($e)]; // key_secret is never exposed
    }

    public function votingOpen(object $e): bool
    {
        return $e->status === 'open' && now()->gte(Common::at($e->opens_at)) && now()->lt(Common::at($e->closes_at));
    }

    public function index(): array
    {
        $cand = DB::table('act_candidates')->where('school_id', Common::school())->selectRaw('election_id, count(*) as n')->groupBy('election_id')->pluck('n', 'election_id');
        $voted = DB::table('act_voters')->where('school_id', Common::school())->selectRaw('election_id, count(*) as n')->groupBy('election_id')->pluck('n', 'election_id');
        return DB::table('act_elections')->where('school_id', Common::school())->orderByDesc('id')->get()
            ->map(fn ($e) => $this->present($e) + ['candidates' => (int) ($cand[$e->id] ?? 0), 'voted' => (int) ($voted[$e->id] ?? 0)])->all();
    }

    // ---- setup -----------------------------------------------------------------------------------

    public function create(array $d): object
    {
        [$opens, $closes] = $this->window($d['opens_at'], $d['closes_at']);
        return Mutation::insert('act_elections', ['title' => trim($d['title']), 'opens_at' => $opens, 'closes_at' => $closes,
            'eligible_grade_levels' => Common::normaliseLevels($d['eligible_grade_levels'] ?? []), 'status' => 'draft'], 'activities.election.create', ['title' => trim($d['title'])]);
    }

    private function window(mixed $opens, mixed $closes): array
    {
        $o = Common::parseLocal($opens);
        $c = Common::parseLocal($closes);
        abort_if(!$o || !$c, 422, 'วันเวลาเปิดหรือปิดการลงคะแนนไม่ถูกต้อง');
        abort_unless($c->gt($o), 422, 'เวลาปิดต้องอยู่หลังเวลาเปิด');
        return [$o, $c];
    }

    public function update(int $id, int $version, array $d): object
    {
        $e = $this->find($id);
        abort_if($e->status === 'closed', 422, 'การเลือกตั้งปิดแล้ว แก้ไขไม่ได้');
        if ($e->status === 'open') {
            // while voting runs only the closing time may move (extend), never the rules
            abort_if(array_diff(array_keys($d), ['closes_at']), 422, 'เมื่อเปิดลงคะแนนแล้ว แก้ได้เฉพาะเวลาปิด');
            $c = Common::parseLocal($d['closes_at'] ?? null);
            abort_if(!$c || $c->lte(now()), 422, 'เวลาปิดต้องอยู่ในอนาคต');
            abort_unless($c->gt(Common::at($e->opens_at)), 422, 'เวลาปิดต้องอยู่หลังเวลาเปิด');
            return Mutation::update('act_elections', $id, $version, ['closes_at' => $c], 'activities.election.extend', ['closes_at' => Common::iso((string) $c)]);
        }
        $changes = [];
        if (isset($d['title'])) $changes['title'] = trim($d['title']);
        if (isset($d['opens_at']) || isset($d['closes_at'])) {
            [$o, $c] = $this->window($d['opens_at'] ?? Common::iso($e->opens_at), $d['closes_at'] ?? Common::iso($e->closes_at));
            $changes += ['opens_at' => $o, 'closes_at' => $c];
        }
        if (array_key_exists('eligible_grade_levels', $d)) $changes['eligible_grade_levels'] = Common::normaliseLevels($d['eligible_grade_levels']);
        return Mutation::update('act_elections', $id, $version, $changes, 'activities.election.update', array_diff_key($changes, ['opens_at' => 1, 'closes_at' => 1]));
    }

    public function destroy(int $id, int $version): void
    {
        $e = $this->find($id);
        abort_unless($e->status === 'draft', 422, 'ลบได้เฉพาะการเลือกตั้งที่ยังเป็นร่าง');
        DB::transaction(function () use ($id, $version) {
            Mutation::delete('act_elections', $id, $version, 'activities.election.delete');
            DB::table('act_candidates')->where('school_id', Common::school())->where('election_id', $id)->delete();
        });
    }

    public function candidates(int $electionId): array
    {
        return DB::table('act_candidates as k')->where('k.school_id', Common::school())->where('k.election_id', $electionId)
            ->leftJoin('students as s', 's.id', '=', 'k.student_id')->leftJoin('classrooms as c', 'c.id', '=', 's.classroom_id')
            ->orderBy('k.number')->select('k.id', 'k.number', 'k.student_id', 'k.slogan', 'k.version', 's.name', 'c.name as classroom_name')->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'number' => (int) $r->number, 'student_id' => (int) $r->student_id, 'name' => $r->name, 'classroom_name' => $r->classroom_name,
                'slogan' => (string) ($r->slogan ?? ''), 'version' => (int) $r->version])->all();
    }

    public function addCandidate(int $electionId, int $number, int $studentId, ?string $slogan): object
    {
        $e = $this->find($electionId);
        abort_unless($e->status === 'draft', 422, 'เพิ่มผู้สมัครได้เฉพาะก่อนเปิดลงคะแนน');
        abort_unless(Common::studentsQuery()->where('s.id', $studentId)->exists(), 422, 'ไม่พบนักเรียนที่กำลังศึกษา');
        abort_if(DB::table('act_candidates')->where('election_id', $electionId)->where('number', $number)->exists(), 409, 'หมายเลขนี้มีผู้สมัครแล้ว');
        abort_if(DB::table('act_candidates')->where('election_id', $electionId)->where('student_id', $studentId)->exists(), 409, 'นักเรียนคนนี้สมัครแล้ว');
        try {
            return Mutation::insert('act_candidates', ['election_id' => $electionId, 'number' => $number, 'student_id' => $studentId, 'slogan' => $slogan], 'activities.candidate.add', ['election_id' => $electionId, 'number' => $number]);
        } catch (QueryException $x) {
            if (DbError::isUnique($x)) abort(409, 'หมายเลขนี้หรือนักเรียนคนนี้มีผู้สมัครแล้ว');
            throw $x;
        }
    }

    public function removeCandidate(int $id, int $version): void
    {
        $k = DB::table('act_candidates')->where('school_id', Common::school())->where('id', $id)->first() ?? abort(404);
        abort_unless($this->find((int) $k->election_id)->status === 'draft', 422, 'ลบผู้สมัครได้เฉพาะก่อนเปิดลงคะแนน');
        Mutation::delete('act_candidates', $id, $version, 'activities.candidate.remove');
    }

    public function transition(int $id, int $version, string $action): object
    {
        return DB::transaction(function () use ($id, $version, $action) {
            $e = DB::table('act_elections')->where('school_id', Common::school())->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            if ($action === 'open') {
                abort_unless($e->status === 'draft', 422, 'เปิดลงคะแนนได้เฉพาะการเลือกตั้งที่ยังเป็นร่าง');
                abort_if(DB::table('act_candidates')->where('election_id', $id)->count() < 1, 422, 'ต้องมีผู้สมัครอย่างน้อย 1 คนก่อนเปิดลงคะแนน');
                abort_unless(Common::at($e->closes_at)->gt(now()), 422, 'เวลาปิดผ่านไปแล้ว กรุณาแก้ไขเวลาก่อนเปิด');
                return Mutation::update('act_elections', $id, $version, ['status' => 'open'], 'activities.election.open', ['title' => $e->title]);
            }
            abort_unless($e->status === 'open', 422, 'ปิดการลงคะแนนได้เฉพาะการเลือกตั้งที่กำลังเปิด');
            return Mutation::update('act_elections', $id, $version, ['status' => 'closed'], 'activities.election.close', ['title' => $e->title]);
        });
    }

    // ---- eligibility and kiosk -------------------------------------------------------------------

    /** Student must be studying and in an eligible grade. Returns the student row (with classroom) or aborts 422. */
    private function eligibleStudent(object $e, int $studentId): object
    {
        $s = Common::studentsQuery()->where('s.id', $studentId)->first();
        abort_if(!$s, 422, 'ไม่พบนักเรียนที่กำลังศึกษา');
        abort_unless(Common::levelAllowed(Common::levels($e->eligible_grade_levels), Common::gradeOf($s)), 422, 'นักเรียนคนนี้ไม่มีสิทธิ์ลงคะแนนในการเลือกตั้งนี้ (ระดับชั้น)');
        return $s;
    }

    public function kiosk(int $electionId, int $classroomId): array
    {
        $e = $this->find($electionId);
        abort_unless(TeacherScope::canClassroom($classroomId), 403, 'ไม่มีสิทธิ์ใช้คูหาของห้องนี้');
        $levels = Common::levels($e->eligible_grade_levels);
        $voted = DB::table('act_voters')->where('election_id', $electionId)->pluck('student_id')->flip();
        $students = Common::studentsQuery()->where('s.classroom_id', $classroomId)->orderBy('s.code')->get()
            ->filter(fn ($s) => Common::levelAllowed($levels, Common::gradeOf($s)))
            ->map(fn ($s) => ['id' => (int) $s->id, 'code' => $s->code, 'name' => $s->name, 'voted' => $voted->has($s->id)])->values()->all();
        return ['election' => $this->present($e), 'candidates' => array_map(fn ($c) => ['id' => $c['id'], 'number' => $c['number'], 'name' => $c['name'], 'slogan' => $c['slogan']], $this->candidates($electionId)),
            'students' => $students, 'voted' => count(array_filter($students, fn ($s) => $s['voted'])), 'total' => count($students)];
    }

    private function ticketName(string $token): string
    {
        return 'act:ticket:'.hash('sha256', $token);
    }

    public function unlock(int $electionId, int $studentId): array
    {
        $e = $this->find($electionId);
        abort_unless($this->votingOpen($e), 422, 'ยังไม่อยู่ในช่วงเวลาลงคะแนน');
        $s = $this->eligibleStudent($e, $studentId);
        abort_unless(Common::canStudent($s), 403, 'ไม่มีสิทธิ์เปิดคูหาให้นักเรียนห้องนี้');
        abort_if(DB::table('act_voters')->where('election_id', $electionId)->where('student_id', $studentId)->exists(), 409, 'นักเรียนคนนี้ลงคะแนนไปแล้ว');
        $token = bin2hex(random_bytes(20));
        Cache::put($this->ticketName($token), ['e' => $electionId, 's' => $studentId, 'u' => (int) auth()->id()], self::TICKET_SECONDS);
        return ['ticket' => $token, 'expires_in' => self::TICKET_SECONDS, 'student' => ['id' => (int) $s->id, 'name' => $s->name]];
    }

    /** Casts one ballot. $candidateId null = abstain. Returns only {ok}; never the choice and never the student. */
    public function vote(int $electionId, string $ticket, ?int $candidateId): array
    {
        $grant = Cache::get($this->ticketName($ticket));
        abort_if(!is_array($grant) || (int) $grant['e'] !== $electionId || (int) $grant['u'] !== (int) auth()->id(), 403, 'คูหายังไม่ถูกปลดล็อก หรือหมดเวลา ให้ครูเลือกชื่อนักเรียนใหม่');
        $studentId = (int) $grant['s'];
        DB::transaction(function () use ($electionId, $studentId, $candidateId) {
            $e = DB::table('act_elections')->where('school_id', Common::school())->where('id', $electionId)->lockForUpdate()->first() ?? abort(404);
            abort_unless($this->votingOpen($e), 422, 'ปิดการลงคะแนนแล้ว');
            $s = $this->eligibleStudent($e, $studentId);
            if ($candidateId !== null) abort_unless(DB::table('act_candidates')->where('election_id', $electionId)->where('id', $candidateId)->exists(), 422, 'ไม่พบผู้สมัครที่เลือก');
            try {
                DB::table('act_voters')->insert(['school_id' => Common::school(), 'election_id' => $electionId, 'student_id' => $studentId, 'classroom_id' => $s->classroom_id, 'voted_at' => Common::today().' 00:00:00']); // day only, same as cast_at: no exact time to line a voter up with a ballot
                DB::table('act_ballots')->insert(['id' => bin2hex(random_bytes(16)), 'election_id' => $electionId, 'voter_key' => bin2hex(random_bytes(32)),
                    'candidate_id' => $candidateId, 'cast_at' => Common::today()]);
            } catch (QueryException $x) {
                if (DbError::isUnique($x)) abort(409, 'นักเรียนคนนี้ลงคะแนนไปแล้ว');
                throw $x;
            }
            Mutation::audit('activities.vote', 'act_elections', $electionId, ['classroom_id' => $s->classroom_id]); // who unlocked, never the choice
        });
        Cache::forget($this->ticketName($ticket));
        return ['ok' => true];
    }

    // ---- turnout and results ---------------------------------------------------------------------

    public function turnout(int $electionId): array
    {
        $e = $this->find($electionId);
        $levels = Common::levels($e->eligible_grade_levels);
        $ids = TeacherScope::classroomIds();
        $rooms = DB::table('classrooms')->where('school_id', Common::school())->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))->orderBy('name')->get();
        $eligible = [];
        foreach (Common::studentCounts() as $r) {
            if (Common::levelAllowed($levels, $r->grade_level !== null && $r->grade_level !== '' ? (string) $r->grade_level : null)) $eligible[(int) $r->classroom_id] = ($eligible[(int) $r->classroom_id] ?? 0) + (int) $r->n;
        }
        $voted = DB::table('act_voters')->where('election_id', $electionId)->selectRaw('classroom_id, count(*) as n')->groupBy('classroom_id')->pluck('n', 'classroom_id');
        $out = [];
        $te = 0;
        $tv = 0;
        foreach ($rooms as $r) {
            $n = $eligible[(int) $r->id] ?? 0;
            if ($n === 0 && !($voted[$r->id] ?? 0)) continue;
            $v = (int) ($voted[$r->id] ?? 0);
            $out[] = ['classroom_id' => (int) $r->id, 'name' => $r->name, 'eligible' => $n, 'voted' => $v];
            $te += $n;
            $tv += $v;
        }
        return ['election' => $this->present($e), 'eligible' => $te, 'voted' => $tv, 'percent' => $te > 0 ? round($tv * 100 / $te, 1) : 0, 'classrooms' => $out, 'scope' => $ids === null ? 'school' : 'mine'];
    }

    public function results(int $electionId): array
    {
        $e = $this->find($electionId);
        abort_unless($e->status === 'closed', 423, 'ดูผลได้หลังปิดการลงคะแนนเท่านั้น');
        $counts = DB::table('act_ballots')->where('election_id', $electionId)->selectRaw('candidate_id, count(*) as n')->groupBy('candidate_id')->pluck('n', 'candidate_id');
        $rows = array_map(fn ($c) => ['candidate_id' => $c['id'], 'number' => $c['number'], 'name' => $c['name'], 'classroom_name' => $c['classroom_name'], 'votes' => (int) ($counts[$c['id']] ?? 0)], $this->candidates($electionId));
        usort($rows, fn ($a, $b) => [$b['votes'], $a['number']] <=> [$a['votes'], $b['number']]);
        $abstain = (int) ($counts[''] ?? 0);
        $total = (int) DB::table('act_ballots')->where('election_id', $electionId)->count();
        $eligible = $this->turnoutEligible($e);
        return ['election' => $this->present($e), 'results' => $rows, 'abstain' => $abstain, 'ballots' => $total, 'eligible' => $eligible,
            'tie' => count($rows) > 1 && $rows[0]['votes'] === $rows[1]['votes'] && $rows[0]['votes'] > 0];
    }

    private function turnoutEligible(object $e): int
    {
        $levels = Common::levels($e->eligible_grade_levels);
        $n = 0;
        foreach (Common::studentCounts() as $r) if (Common::levelAllowed($levels, $r->grade_level !== null && $r->grade_level !== '' ? (string) $r->grade_level : null)) $n += (int) $r->n;
        return $n;
    }
}
