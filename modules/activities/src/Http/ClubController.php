<?php

namespace Saan\Modules\Activities\Http;

use App\Support\Saan\Idempotency;
use App\Support\Saan\TeacherScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Saan\Modules\Activities\Certificates;
use Saan\Modules\Activities\Clubs;
use Saan\Modules\Activities\Common;

/** /api/activities/clubs|members|sessions|settings|classrooms: thin HTTP layer; rules live in Clubs and Certificates. */
class ClubController
{
    private const MESSAGES = [
        'version.required' => 'ไม่พบเลขเวอร์ชันของข้อมูล กรุณาโหลดใหม่', 'name.required' => 'กรุณาระบุชื่อชุมนุม', 'date.required' => 'กรุณาเลือกวันที่', 'date.date_format' => 'วันที่ไม่ถูกต้อง',
        'student_ids.required' => 'กรุณาเลือกนักเรียน', 'reason.required' => 'กรุณาระบุเหตุผลที่แก้ผล', 'result.in' => 'ผลต้องเป็น ผ หรือ มผ',
    ];

    public function __construct(private Clubs $clubs, private Certificates $certs)
    {
    }

    private function v(Request $r, array $rules): array
    {
        return $r->validate($rules, self::MESSAGES);
    }

    private function clubRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        return ['name' => $req.'|string|min:1|max:160', 'term_id' => 'sometimes|nullable|integer|min:1', 'advisor_teacher_id' => 'sometimes|nullable|integer|min:1', 'capacity' => 'sometimes|nullable|integer|min:1|max:2000',
            'grade_levels' => 'sometimes|nullable|array|max:20', 'grade_levels.*' => 'string|max:20', 'description' => 'sometimes|nullable|string|max:2000', 'is_open' => 'sometimes|boolean'];
    }

    public function index(Request $r)
    {
        $termId = Common::termId($r->query('term_id'));
        return response()->json(['term_id' => $termId, 'term_label' => Common::termLabel($termId), 'data' => $this->clubs->index($termId),
            'terms' => DB::table('academic_terms')->where('school_id', Common::school())->orderByDesc('year')->orderByDesc('term')->get(['id', 'year', 'term'])->map(fn ($t) => ['id' => (int) $t->id, 'label' => 'ปีการศึกษา '.$t->year.' ภาคเรียนที่ '.$t->term])->all(),
            'teachers' => Common::isManager() ? DB::table('teachers')->where('school_id', Common::school())->orderBy('name')->get(['id', 'name'])->all() : [],
            'settings' => $this->clubs->settings(), 'role' => auth()->user()->role, 'teacher_id' => auth()->user()->teacher_id ? (int) auth()->user()->teacher_id : null]);
    }

    public function store(Request $r)
    {
        $d = $this->v($r, $this->clubRules(true));
        return Idempotency::run($r, fn () => response()->json($this->clubs->present($this->clubs->create($d)), 201));
    }

    public function show(int $id)
    {
        $club = $this->clubs->find($id);
        $this->clubs->requireManage($club);
        return response()->json($this->clubs->detail($club));
    }

    public function update(Request $r, int $id)
    {
        $d = $this->v($r, $this->clubRules(false) + ['version' => 'required|integer|min:1']);
        $version = (int) $d['version'];
        unset($d['version']);
        return response()->json($this->clubs->present($this->clubs->update($id, $version, $d)));
    }

    public function destroy(Request $r, int $id)
    {
        $d = $this->v($r, ['version' => 'required|integer|min:1']);
        $this->clubs->destroy($id, (int) $d['version']);
        return response()->json(['deleted' => true]);
    }

    // classrooms the signed-in user may act on, and their students with the club they are already in this term
    public function classrooms()
    {
        $ids = TeacherScope::classroomIds();
        return response()->json(['data' => DB::table('classrooms')->where('school_id', Common::school())->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))->orderBy('name')->get(['id', 'name'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->all()]);
    }

    public function classroomStudents(Request $r, int $id)
    {
        abort_unless(TeacherScope::canClassroom($id), 403, 'ไม่มีสิทธิ์ดูนักเรียนห้องนี้');
        $termId = Common::termId($r->query('term_id'));
        $students = Common::studentsQuery()->where('s.classroom_id', $id)->orderBy('s.code')->get();
        $in = DB::table('act_members as m')->join('act_clubs as c', 'c.id', '=', 'm.club_id')->where('m.school_id', Common::school())->where('m.term_id', $termId)->whereNotNull('m.active_key')
            ->whereIn('m.student_id', $students->pluck('id')->all() ?: [0])
            ->select('m.student_id', 'm.status', 'c.id as club_id', 'c.name')->get()->keyBy('student_id');
        return response()->json(['data' => $students->map(fn ($s) => ['id' => (int) $s->id, 'code' => $s->code, 'name' => $s->name,
            'club' => isset($in[$s->id]) ? ['id' => (int) $in[$s->id]->club_id, 'name' => $in[$s->id]->name, 'status' => $in[$s->id]->status] : null])->all()]);
    }

    /** One student by exact code (admin/principal), for picking candidates without walking every classroom. */
    public function studentLookup(Request $r)
    {
        $code = trim((string) $r->query('code', ''));
        abort_if($code === '' || mb_strlen($code) > 40, 422, 'กรุณาระบุรหัสนักเรียน');
        $s = Common::studentsQuery()->whereRaw('lower(s.code) = ?', [mb_strtolower($code)])->first();
        abort_if(!$s, 404, 'ไม่พบรหัสนักเรียนนี้');
        return response()->json(['id' => (int) $s->id, 'code' => $s->code, 'name' => $s->name, 'classroom_name' => $s->classroom_name]);
    }

    public function enroll(Request $r, int $id)
    {
        $d = $this->v($r, ['student_ids' => 'required_without:classroom_id|array|min:1|max:200', 'student_ids.*' => 'integer|min:1', 'classroom_id' => 'sometimes|integer|min:1']);
        $ids = $d['student_ids'] ?? [];
        if (isset($d['classroom_id'])) {
            abort_unless(TeacherScope::canClassroom((int) $d['classroom_id']), 403, 'ไม่มีสิทธิ์จัดการนักเรียนห้องนี้');
            $ids = array_merge($ids, Common::studentsQuery()->where('s.classroom_id', (int) $d['classroom_id'])->orderBy('s.code')->pluck('s.id')->map(fn ($v) => (int) $v)->all());
        }
        return Idempotency::run($r, fn () => response()->json($this->clubs->enroll($id, $ids)));
    }

    public function leave(Request $r, int $id)
    {
        $d = $this->v($r, ['version' => 'required|integer|min:1']);
        $m = $this->clubs->leave($id, (int) $d['version']);
        return response()->json(['id' => (int) $m->id, 'status' => $m->status, 'version' => (int) $m->version]);
    }

    public function sessionStore(Request $r, int $id)
    {
        $club = $this->clubs->find($id);
        $this->clubs->requireManage($club);
        $d = $this->v($r, ['date' => 'required|date_format:Y-m-d', 'topic' => 'nullable|string|max:200']);
        $s = $this->clubs->sessionCreate($id, $d['date'], $d['topic'] ?? null);
        return response()->json(['id' => (int) $s->id, 'date' => (string) $s->date, 'topic' => (string) ($s->topic ?? ''), 'version' => (int) $s->version], 201);
    }

    public function sessionUpdate(Request $r, int $id)
    {
        $this->clubs->requireManage($this->clubs->sessionClub($id));
        $d = $this->v($r, ['version' => 'required|integer|min:1', 'date' => 'sometimes|date_format:Y-m-d', 'topic' => 'sometimes|nullable|string|max:200']);
        $version = (int) $d['version'];
        unset($d['version']);
        $s = $this->clubs->sessionUpdate($id, $version, $d);
        return response()->json(['id' => (int) $s->id, 'date' => (string) $s->date, 'topic' => (string) ($s->topic ?? ''), 'version' => (int) $s->version]);
    }

    public function sessionDestroy(Request $r, int $id)
    {
        $this->clubs->requireManage($this->clubs->sessionClub($id));
        $d = $this->v($r, ['version' => 'required|integer|min:1']);
        $this->clubs->sessionDestroy($id, (int) $d['version']);
        return response()->json(['deleted' => true]);
    }

    public function attendance(int $id)
    {
        $this->clubs->requireManage($this->clubs->sessionClub($id));
        return response()->json($this->clubs->attendance($id));
    }

    public function attendanceSave(Request $r, int $id)
    {
        $this->clubs->requireManage($this->clubs->sessionClub($id));
        $d = $this->v($r, ['version' => 'required|integer|min:1', 'records' => 'required|array|min:1|max:2000', 'records.*.student_id' => 'required|integer|min:1', 'records.*.present' => 'required|boolean']);
        return response()->json($this->clubs->saveAttendance($id, (int) $d['version'], $d['records']));
    }

    public function compute(int $id)
    {
        $this->clubs->requireManage($this->clubs->find($id));
        return response()->json($this->clubs->computeResults($id));
    }

    public function override(Request $r, int $id)
    {
        $d = $this->v($r, ['version' => 'required|integer|min:1', 'result' => 'present|nullable|in:ผ,มผ', 'reason' => 'required|string|min:3|max:300']);
        $m = $this->clubs->overrideResult($id, (int) $d['version'], $d['result'], trim($d['reason']));
        return response()->json(['id' => (int) $m->id, 'result' => $m->result, 'result_manual' => (bool) $m->result_manual, 'version' => (int) $m->version]);
    }

    public function settings()
    {
        return response()->json($this->clubs->settings());
    }

    public function settingsSave(Request $r)
    {
        $d = $this->v($r, ['pass_percent' => 'sometimes|integer|min:1|max:100', 'cert_prefix' => ['sometimes', 'nullable', 'regex:/^[A-Za-z0-9]{0,12}$/'], 'signer_name' => 'sometimes|nullable|string|max:120', 'signer_title' => 'sometimes|nullable|string|max:120']);
        return response()->json($this->clubs->saveSettings($d));
    }

    public function certificates(int $id)
    {
        return response()->json(['data' => $this->certs->forClub($id)]);
    }

    public function certificatesIssue(Request $r, int $id)
    {
        return Idempotency::run($r, fn () => response()->json($this->certs->issue($id), 201));
    }

    /** Printable A4 landscape page: all certificates of a club, or one with ?id=. */
    public function certificatesPrint(Request $r, int $id)
    {
        $only = $r->query('id') !== null ? (int) $r->query('id') : null;
        $rows = $this->certs->forClub($id, $only);
        abort_if(!$rows, 404, 'ยังไม่มีเกียรติบัตร');
        return response()->view('activities::certificate', ['rows' => $rows])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }
}
