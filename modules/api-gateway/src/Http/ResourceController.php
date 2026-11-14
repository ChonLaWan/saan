<?php

namespace Saan\Modules\ApiGateway\Http;

use App\Support\Saan\TermScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Saan\Modules\ApiGateway\Gateway;
use Saan\Modules\ApiGateway\Net;
use Saan\Modules\ApiGateway\Presenter;

/**
 * Read-only /api/v1/* resources. Scope is enforced by ApiAuth, fields by Presenter.
 * Cursor pagination: ?limit=1..500 (default 100) and ?cursor=<opaque from next_cursor>, ordered by id.
 */
class ResourceController
{
    private const STATUSES = ['studying', 'transferred_out', 'graduated', 'left'];

    public function students(Request $r): JsonResponse
    {
        $scopes = $r->attributes->get('api_scopes');
        [$limit, $after] = $this->page($r);
        $school = Gateway::school();
        $has = fn (string $c) => Schema::hasColumn('students', $c);

        $q = DB::table('students as s')->leftJoin('classrooms as c', fn ($j) => $j->on('c.id', '=', 's.classroom_id')->where('c.school_id', $school))
            ->where('s.school_id', $school);
        $status = (string) $r->query('status', 'studying');
        if (!in_array($status, self::STATUSES, true)) return $this->bad('status ไม่ถูกต้อง');
        if ($has('status')) $q->where('s.status', $status);
        if (($classroom = $this->classroomParam($r)) !== null) $q->where('s.classroom_id', $classroom);
        if ($r->query('term') !== null && $r->query('term') !== '') {
            $term = $this->term($r);
            if ($term === null) return $this->notFound('ไม่พบภาคเรียน');
            // the roster is today's: a past term's roster is not stored, so it must not be served as if it were
            if ($term !== TermScope::current()?->id) return $this->bad('term รองรับเฉพาะภาคเรียนปัจจุบัน (ไม่มีรายชื่อนักเรียนย้อนหลังตามภาคเรียน)');
            $q->whereIn('s.classroom_id', $this->classroomsInTerm($term));
        }
        if ($after) $q->where('s.id', '>', $after);

        $cols = ['s.id', 's.code', 's.name', 's.classroom_id', 'c.name as classroom_name'];
        foreach (['prefix', 'first_name', 'last_name', 'status'] as $col) if ($has($col)) $cols[] = 's.'.$col;
        $rows = $q->orderBy('s.id')->limit($limit + 1)->get($cols);
        return $this->paged($rows, $limit, function ($pageRows) use ($scopes) {
            $contact = Presenter::has($scopes, 'students:read:contact') ? $this->contacts($pageRows->pluck('id')->all()) : [];
            return $pageRows->map(fn ($row) => Presenter::student($row, $scopes, $contact[$row->id] ?? []))->all();
        });
    }

    public function classrooms(Request $r): JsonResponse
    {
        [$limit, $after] = $this->page($r);
        $q = DB::table('classrooms')->where('school_id', Gateway::school());
        if (Schema::hasColumn('classrooms', 'is_active')) $q->where('is_active', true);
        if ($after) $q->where('id', '>', $after);
        $rows = $q->orderBy('id')->limit($limit + 1)->get();
        return $this->paged($rows, $limit, function ($pageRows) {
            $teachers = DB::table('teachers')->where('school_id', Gateway::school())->whereIn('id', $pageRows->pluck('homeroom_teacher_id')->filter()->unique()->all())->get()->keyBy('id');
            return $pageRows->map(fn ($row) => Presenter::classroomRow($row, $teachers[$row->homeroom_teacher_id] ?? null))->all();
        });
    }

    public function teachers(Request $r): JsonResponse
    {
        [$limit, $after] = $this->page($r);
        $q = DB::table('teachers')->where('school_id', Gateway::school());
        if (Schema::hasColumn('teachers', 'is_active')) $q->where('is_active', true);
        if ($after) $q->where('id', '>', $after);
        $rows = $q->orderBy('id')->limit($limit + 1)->get();
        return $this->paged($rows, $limit, fn ($pageRows) => $pageRows->map(fn ($row) => Presenter::teacher($row))->all());
    }

    public function courses(Request $r): JsonResponse
    {
        // tables outlive a module that was switched off: only an ACTIVE module may serve its data to third-party apps
        if (!Schema::hasTable('asm_courses') || !Schema::hasTable('asm_subjects') || !app(\App\Support\Modules\ModuleRegistry::class)->active('assessment')) {
            return Gateway::error('not_available', 'ระบบนี้ยังไม่ได้ติดตั้งหรือปิดโมดูลวัดผลอยู่', 404);
        }
        [$limit, $after] = $this->page($r);
        $term = $this->term($r);
        if ($term === null) return $this->notFound('ไม่พบภาคเรียน');
        $school = Gateway::school();
        $q = DB::table('asm_courses as k')
            ->join('asm_subjects as s', fn ($j) => $j->on('s.id', '=', 'k.subject_id')->where('s.school_id', $school))
            ->leftJoin('classrooms as c', fn ($j) => $j->on('c.id', '=', 'k.classroom_id')->where('c.school_id', $school))
            ->leftJoin('teachers as t', fn ($j) => $j->on('t.id', '=', 'k.teacher_id')->where('t.school_id', $school))
            ->where('k.school_id', $school)->where('k.term_id', $term);
        if (($classroom = $this->classroomParam($r)) !== null) $q->where('k.classroom_id', $classroom);
        if ($after) $q->where('k.id', '>', $after);
        $rows = $q->orderBy('k.id')->limit($limit + 1)->get(['k.id', 'k.term_id', 'k.classroom_id', 'k.teacher_id', 'k.status',
            's.code as subject_code', 's.name as subject_name', 'c.name as classroom_name', 't.name as teacher_name']);
        return $this->paged($rows, $limit, fn ($pageRows) => $pageRows->map(fn ($row) => Presenter::course($row))->all());
    }

    /** Aggregated counts only: no student is identifiable from this response. */
    public function attendanceSummary(Request $r): JsonResponse
    {
        if (!app(\App\Support\Modules\ModuleRegistry::class)->active('attendance')) return Gateway::error('not_available', 'ระบบนี้ยังไม่ได้ติดตั้งหรือปิดโมดูลเช็กชื่ออยู่', 404);
        $term = TermScope::current();
        $from = (string) $r->query('from', $term?->starts_on ?? CarbonImmutable::now()->subDays(30)->toDateString());
        $to = (string) $r->query('to', CarbonImmutable::now()->toDateString());
        foreach ([$from, $to] as $d) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d) || !checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4))) return $this->bad('วันที่ต้องเป็นรูปแบบ YYYY-MM-DD');
        if ($from > $to) return $this->bad('from ต้องไม่เกิน to');
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366) return $this->bad('ช่วงวันที่ยาวเกิน 366 วัน');

        $school = Gateway::school();
        $classroom = $this->classroomParam($r);
        $studying = Schema::hasColumn('students', 'status');
        // history: the room recorded on the mark itself (falls back to the current room for rows from before the column existed); no filter on current status
        $room = Schema::hasColumn('attendances', 'classroom_id') ? 'COALESCE(a.classroom_id, s.classroom_id)' : 's.classroom_id';
        $q = DB::table('attendances as a')->join('students as s', fn ($j) => $j->on('s.id', '=', 'a.student_id')->where('s.school_id', $school))
            ->join('classrooms as c', fn ($j) => $j->on('c.id', '=', DB::raw($room))->where('c.school_id', $school))
            ->where('a.school_id', $school)->whereBetween('a.date', [$from, $to]);
        if ($classroom !== null) $q->whereRaw($room.' = ?', [$classroom]);
        $rows = $q->groupBy('c.id', 'c.name', 'a.date', 'a.status')->orderBy('c.id')
            ->get(['c.id as classroom_id', 'c.name as classroom_name', 'a.date', 'a.status', DB::raw('COUNT(*) as n')]);

        // The attendance module records exceptions only: on a confirmed class day everybody without an exception row was present.
        $confirmed = []; // classroom id => [date => true]
        if (Schema::hasTable('att_class_days')) {
            $cq = DB::table('att_class_days')->where('school_id', $school)->whereNotNull('confirmed_at')->whereBetween('date', [$from, $to]);
            if ($classroom !== null) $cq->where('classroom_id', $classroom);
            $hasCount = Schema::hasColumn('att_class_days', 'student_count');
            foreach ($cq->get($hasCount ? ['classroom_id', 'date', 'student_count'] : ['classroom_id', 'date']) as $d) {
                $confirmed[(int) $d->classroom_id][substr((string) $d->date, 0, 10)] = $hasCount && $d->student_count !== null ? (int) $d->student_count : true; // roster size of that day, true = unknown
            }
        }

        $by = [];
        $exceptions = []; // classroom id => date => rows that are not present
        foreach ($rows as $row) {
            $cid = (int) $row->classroom_id;
            $date = substr((string) $row->date, 0, 10);
            $by[$cid]['name'] = $row->classroom_name;
            $n = (int) $row->n;
            if ($row->status !== 'present') {
                $by[$cid]['counts'][(string) $row->status] = ($by[$cid]['counts'][(string) $row->status] ?? 0) + $n;
                $exceptions[$cid][$date] = ($exceptions[$cid][$date] ?? 0) + $n;
            } elseif (empty($confirmed[$cid][$date])) {
                $by[$cid]['counts']['present'] = ($by[$cid]['counts']['present'] ?? 0) + $n; // unconfirmed day: only what was recorded
            }
        }
        if ($confirmed) {
            $size = DB::table('students')->where('school_id', $school)->whereIn('classroom_id', array_keys($confirmed))
                ->when($studying, fn ($s) => $s->where('status', 'studying'))->selectRaw('classroom_id, count(*) as n')->groupBy('classroom_id')->pluck('n', 'classroom_id')->all();
            $names = DB::table('classrooms')->where('school_id', $school)->whereIn('id', array_keys($confirmed))->pluck('name', 'id')->all();
            foreach ($confirmed as $cid => $days) {
                if (!isset($names[$cid])) continue;
                $by[$cid]['name'] ??= $names[$cid];
                foreach ($days as $date => $dayCount) {
                    $roster = is_int($dayCount) ? $dayCount : (int) ($size[$cid] ?? 0); // stored roster size of that day, else today's size (legacy rows)
                    $by[$cid]['counts']['present'] = ($by[$cid]['counts']['present'] ?? 0) + max(0, $roster - ($exceptions[$cid][$date] ?? 0));
                }
                $by[$cid]['days_checked'] = count($days);
            }
        }
        ksort($by);
        $data = [];
        foreach ($by as $id => $info) $data[] = Presenter::attendance((int) $id, (string) $info['name'], $from, $to, $info['counts'] ?? [], (int) ($info['days_checked'] ?? 0));
        return response()->json(['data' => $data, 'next_cursor' => null]);
    }

    /** The student behind an SSO token. Staff and client_credentials tokens have no "me". */
    public function me(Request $r): JsonResponse
    {
        $token = $r->attributes->get('api_client');
        if ($token->user_kind !== 'student' || !$token->subject_id) return Gateway::error('invalid_token', 'โทเคนนี้ไม่ได้ออกให้นักเรียน', 403);
        $row = $this->studentRow((int) $token->subject_id);
        if (!$row || ($row->status ?? 'studying') !== 'studying') {
            return Gateway::error('invalid_token', 'โทเคนไม่ถูกต้อง นักเรียนไม่อยู่ในสถานะกำลังศึกษา', 401, ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        }
        return response()->json(['data' => Presenter::me($row)]);
    }

    private function studentRow(int $id): ?object
    {
        $school = Gateway::school();
        $cols = ['s.id', 's.code', 's.name', 's.classroom_id', 'c.name as classroom_name'];
        foreach (['prefix', 'first_name', 'last_name', 'status'] as $col) if (Schema::hasColumn('students', $col)) $cols[] = 's.'.$col;
        return DB::table('students as s')->leftJoin('classrooms as c', fn ($j) => $j->on('c.id', '=', 's.classroom_id')->where('c.school_id', $school))
            ->where('s.school_id', $school)->where('s.id', $id)->first($cols);
    }

    /**
     * Guardian contacts per student, only for links the guardian approved for app sharing. That consent flag
     * (reg_student_guardians.share_contact_with_apps) does not exist yet, so until the registry adds it this returns nothing.
     * @return array<int,array>
     */
    private function contacts(array $studentIds): array
    {
        if (!$studentIds || !app(\App\Support\Modules\ModuleRegistry::class)->active('registry') || !Schema::hasTable('reg_student_guardians') || !Schema::hasTable('reg_guardians')
            || !Schema::hasColumn('reg_student_guardians', 'share_contact_with_apps')) return [];
        $school = Gateway::school();
        $cols = ['l.student_id', 'l.relationship', 'l.is_primary'];
        foreach (['display_name', 'email'] as $c) if (Schema::hasColumn('reg_guardians', $c)) $cols[] = 'g.'.$c;
        $out = [];
        $rows = DB::table('reg_student_guardians as l')->join('reg_guardians as g', 'g.id', '=', 'l.guardian_id')
            ->where('l.school_id', $school)->where('g.school_id', $school)->where('l.share_contact_with_apps', true)
            ->whereIn('l.student_id', $studentIds)->orderBy('l.id')->get($cols);
        foreach ($rows as $row) $out[(int) $row->student_id][] = Presenter::guardian($row);
        return $out;
    }

    /** @return array{0:int,1:int} limit and last seen id */
    private function page(Request $r): array
    {
        $limit = max(1, min(500, (int) $r->query('limit', 100)));
        $cursor = (string) $r->query('cursor', '');
        $after = 0;
        if ($cursor !== '') {
            $d = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);
            if (!is_array($d) || !isset($d['after']) || !is_int($d['after']) || $d['after'] < 0) abort(response()->json(['error' => 'invalid_request', 'error_description' => 'cursor ไม่ถูกต้อง'], 422));
            $after = $d['after'];
        }
        return [$limit, $after];
    }

    private function paged($rows, int $limit, \Closure $map): JsonResponse
    {
        $more = $rows->count() > $limit;
        $pageRows = $more ? $rows->take($limit)->values() : $rows->values();
        $idOf = fn ($row) => (int) ($row->id);
        $next = $more ? Net::b64url(json_encode(['after' => $idOf($pageRows->last())])) : null;
        return response()->json(['data' => $map($pageRows), 'next_cursor' => $next]);
    }

    private function classroomParam(Request $r): ?int
    {
        $v = trim((string) $r->query('classroom', ''));
        if ($v === '') return null;
        if (ctype_digit($v)) return (int) $v;
        $id = DB::table('classrooms')->where('school_id', Gateway::school())
            ->where(function ($w) use ($v) {
                if (Schema::hasColumn('classrooms', 'name_key')) $w->where('name_key', Net::key($v))->orWhere('name', $v);
                else $w->where('name', $v);
            })->value('id');
        return $id ? (int) $id : 0; // 0 matches nothing
    }

    /** Requested term id (must belong to this school) or the current term. */
    private function term(Request $r): ?int
    {
        $v = $r->query('term');
        if ($v === null || $v === '') return TermScope::current()?->id;
        if (!ctype_digit((string) $v)) return null;
        $found = DB::table('academic_terms')->where('school_id', Gateway::school())->where('id', (int) $v)->value('id');
        return $found ? (int) $found : null;
    }

    /** Classrooms that have timetable lessons or assessment courses in the term. */
    private function classroomsInTerm(int $term): array
    {
        $school = Gateway::school();
        $ids = DB::table('lessons')->where('school_id', $school)->where('term_id', $term)
            ->when(Schema::hasColumn('lessons', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))->distinct()->pluck('classroom_id')->all();
        if (Schema::hasTable('asm_courses')) {
            $ids = array_merge($ids, DB::table('asm_courses')->where('school_id', $school)->where('term_id', $term)->distinct()->pluck('classroom_id')->all());
        }
        return array_values(array_unique(array_map('intval', $ids))) ?: [0];
    }

    private function bad(string $why): JsonResponse
    {
        return Gateway::error('invalid_request', $why, 422);
    }

    private function notFound(string $why): JsonResponse
    {
        return Gateway::error('not_found', $why, 404);
    }
}
