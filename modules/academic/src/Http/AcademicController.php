<?php

namespace Saan\Modules\Academic\Http;

use App\Support\Saan\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Saan\Modules\Academic\Academic;

/** /api/academic/*: thin HTTP layer; rules live in Saan\Modules\Academic\Academic. Role checks are route middleware. */
class AcademicController
{
    public function __construct(private Academic $a) {}

    private const MESSAGES = [
        'version.required' => 'ไม่พบเลขเวอร์ชันของข้อมูล กรุณาโหลดใหม่',
        'starts_on.date_format' => 'วันที่ไม่ถูกต้อง (รูปแบบ ปปปป-ดด-วว)', 'ends_on.date_format' => 'วันที่ไม่ถูกต้อง (รูปแบบ ปปปป-ดด-วว)',
        'year.between' => 'ปีการศึกษาต้องเป็น พ.ศ. ระหว่าง 2500 ถึง 2700', 'term.between' => 'ภาคเรียนต้องเป็น 1 ถึง 3',
    ];

    private function v(Request $r, array $rules): array
    {
        return $r->validate($rules, self::MESSAGES);
    }

    // ---------------------------------------------------------------- setup

    public function setup(): JsonResponse { return response()->json($this->a->setup()); }

    // ---------------------------------------------------------------- terms

    private const TERM = ['year' => 'required|integer|between:2500,2700', 'term' => 'required|integer|between:1,3', 'label' => 'nullable|string|max:60',
        'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d'];

    public function terms(): JsonResponse { return response()->json(['data' => $this->a->terms(), 'setting_version' => $this->a->currentTermSettingVersion()]); }

    public function termCreate(Request $r): JsonResponse { return response()->json($this->a->createTerm($this->v($r, self::TERM)), 201); }

    public function termUpdate(Request $r, int $id): JsonResponse
    {
        $d = $this->v($r, self::TERM + ['version' => 'required|integer|min:1']);
        return response()->json($this->a->updateTerm($id, (int) $d['version'], $d));
    }

    public function termDelete(Request $r, int $id): JsonResponse
    {
        $d = $this->v($r, ['version' => 'required|integer|min:1']);
        $this->a->deleteTerm($id, (int) $d['version']);
        return response()->json(['ok' => true]);
    }

    public function termCurrent(Request $r, int $id): JsonResponse
    {
        $d = $this->v($r, ['expected_version' => 'nullable|integer|min:1']);
        // optional lock: a client that sends expected_version (null = "no current term set yet") gets 409 when someone else changed it
        return response()->json($this->a->setCurrentTerm($id, $r->has('expected_version'), isset($d['expected_version']) ? (int) $d['expected_version'] : null));
    }

    // -------------------------------------------------------------- periods

    public function periods(): JsonResponse { return response()->json($this->a->periods()); }

    public function periodsSave(Request $r): JsonResponse
    {
        $d = $this->v($r, ['token' => 'required|string|max:64', 'periods' => 'required|array|max:24', 'periods.*' => 'array:starts_at,ends_at,kind',
            'periods.*.starts_at' => 'required|string|max:5', 'periods.*.ends_at' => 'required|string|max:5', 'periods.*.kind' => 'nullable|string|max:12']);
        return response()->json($this->a->replacePeriods($d['periods'], $d['token']));
    }

    // ----------------------------------------------------------- classrooms

    private const ROOM = ['name' => 'nullable|string|max:60', 'grade_level' => 'nullable|string|max:20', 'section' => 'nullable|integer|between:1,99',
        'homeroom_teacher_id' => 'nullable|integer|min:1', 'is_active' => 'sometimes|boolean'];

    public function classrooms(): JsonResponse { return response()->json(['data' => $this->a->classrooms()]); }

    public function classroomCreate(Request $r): JsonResponse { return response()->json($this->a->createClassroom($this->v($r, self::ROOM)), 201); }

    public function classroomUpdate(Request $r, int $id): JsonResponse
    {
        $d = $this->v($r, self::ROOM + ['version' => 'required|integer|min:1']);
        return response()->json($this->a->updateClassroom($id, (int) $d['version'], $d));
    }

    public function classroomDelete(Request $r, int $id): JsonResponse
    {
        $d = $this->v($r, ['version' => 'required|integer|min:1']);
        $this->a->deleteClassroom($id, (int) $d['version']);
        return response()->json(['ok' => true]);
    }

    public function classroomsBulk(Request $r)
    {
        $d = $this->v($r, ['pattern' => 'nullable|string|max:500', 'grades' => 'nullable|array|max:40', 'grades.*' => 'string|max:20', 'sections' => 'required|integer|between:1,20']);
        $grades = array_merge($d['grades'] ?? [], $this->a->expandGrades((string) ($d['pattern'] ?? '')));
        return Idempotency::run($r, function () use ($grades, $d) {
            $out = $this->a->bulkClassrooms($grades, (int) $d['sections']);
            return response()->json($out, $out['created'] > 0 ? 201 : 200);
        });
    }

    public function homerooms(Request $r): JsonResponse
    {
        $d = $this->v($r, ['assignments' => 'required|array|min:1|max:200', 'assignments.*' => 'array:classroom_id,version,teacher_id', 'assignments.*.classroom_id' => 'required|integer|min:1',
            'assignments.*.version' => 'required|integer|min:1', 'assignments.*.teacher_id' => 'nullable|integer|min:1']);
        return response()->json(['data' => $this->a->setHomerooms($d['assignments'])]);
    }

    // ------------------------------------------------------------- teachers

    private const TEACHER = ['prefix' => 'nullable|string|max:30', 'first_name' => 'required|string|max:70', 'last_name' => 'nullable|string|max:80',
        'subject_group' => 'nullable|string|max:120', 'is_active' => 'sometimes|boolean'];

    public function teachers(Request $r): JsonResponse
    {
        $rows = $this->a->teachers();
        // plain teachers may read the staff list for scheduling but never see account status
        if ($r->user()->role === 'teacher') $rows = array_map(fn ($t) => array_intersect_key($t, array_flip(['id', 'name', 'subject_group', 'is_active'])), $rows);
        return response()->json(['data' => $rows]);
    }

    public function teacherCreate(Request $r): JsonResponse { return response()->json($this->a->createTeacher($this->v($r, self::TEACHER)), 201); }

    public function teacherUpdate(Request $r, int $id): JsonResponse
    {
        $d = $this->v($r, self::TEACHER + ['version' => 'required|integer|min:1']);
        return response()->json($this->a->updateTeacher($id, (int) $d['version'], $d));
    }

    public function teacherDelete(Request $r, int $id): JsonResponse
    {
        $d = $this->v($r, ['version' => 'required|integer|min:1']);
        $this->a->deleteTeacher($id, (int) $d['version']);
        return response()->json(['ok' => true]);
    }

    public function teachersBulk(Request $r)
    {
        $d = $this->v($r, ['text' => 'required|string|max:60000', 'dry_run' => 'sometimes|boolean']);
        $dry = (bool) ($d['dry_run'] ?? false);
        if ($dry) return response()->json($this->a->bulkTeachers($d['text'], true));
        return Idempotency::run($r, function () use ($d) {
            $out = $this->a->bulkTeachers($d['text'], false);
            return response()->json($out, $out['created'] ? 201 : 200);
        });
    }

    public function teacherAccount(Request $r, int $id)
    {
        $d = $this->v($r, ['email' => 'required|email|max:254']);
        return Idempotency::run($r, fn () => response()->json($this->a->createTeacherAccount($id, $d['email']), 201));
    }

    // ------------------------------------------------------------- calendar

    private const DAY = ['date' => 'required|date_format:Y-m-d', 'kind' => 'required|in:holiday,event,exam,no_class', 'title' => 'required|string|max:160', 'term_id' => 'nullable|integer|min:1'];

    public function calendar(Request $r): JsonResponse
    {
        $d = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'term_id' => 'nullable|integer|min:1']);
        return response()->json(['data' => $this->a->days($d['from'] ?? null, $d['to'] ?? null, isset($d['term_id']) ? (int) $d['term_id'] : null)]);
    }

    public function dayCreate(Request $r): JsonResponse { return response()->json($this->a->createDay($this->v($r, self::DAY)), 201); }

    public function dayUpdate(Request $r, int $id): JsonResponse
    {
        $d = $this->v($r, self::DAY + ['version' => 'required|integer|min:1']);
        return response()->json($this->a->updateDay($id, (int) $d['version'], $d));
    }

    public function dayDelete(Request $r, int $id): JsonResponse
    {
        $d = $this->v($r, ['version' => 'required|integer|min:1']);
        $this->a->deleteDay($id, (int) $d['version']);
        return response()->json(['ok' => true]);
    }
}
