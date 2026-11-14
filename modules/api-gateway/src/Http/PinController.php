<?php

namespace Saan\Modules\ApiGateway\Http;

use App\Support\Saan\Mutation;
use App\Support\Saan\TeacherScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Saan\Modules\ApiGateway\Gateway;
use Saan\Modules\ApiGateway\Net;
use Saan\Modules\ApiGateway\Pins;
use Saan\Modules\ApiGateway\Presenter;

/**
 * Student PIN issue/reset for the learning-app sign-in. Admin and principal: any class. Teacher: homeroom class only.
 * PINs are returned once in the response of issue/reset (for printing slips) and are never stored in clear text or in the audit log.
 * Like the client secret, these responses are not Idempotency-Key cached, so a PIN never sits in the cache.
 */
class PinController
{
    public function classrooms(): JsonResponse
    {
        $ids = TeacherScope::homeroomIds();
        $q = DB::table('classrooms')->where('school_id', Gateway::school());
        if (Schema::hasColumn('classrooms', 'is_active')) $q->where('is_active', true);
        if ($ids !== null) $q->whereIn('id', $ids ?: [0]);
        return response()->json(['data' => $q->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->all()]);
    }

    public function roster(int $classroomId): JsonResponse
    {
        $this->authorizeClass($classroomId);
        $students = $this->students($classroomId);
        $pins = DB::table('api_student_pins')->where('school_id', Gateway::school())->whereIn('student_id', $students->pluck('id')->all() ?: [0])->get()->keyBy('student_id');
        $now = time();
        $data = $students->map(function ($s) use ($pins, $now) {
            $p = $pins[$s->id] ?? null;
            return ['id' => (int) $s->id, 'code' => $s->code, 'display_name' => Presenter::displayName($s), 'has_pin' => (bool) $p,
                'locked' => $p && (Pins::needsReset($p) || ($p->locked_until !== null && strtotime((string) $p->locked_until) > $now)),
                'needs_reset' => $p && Pins::needsReset($p), 'issued_at' => $p?->issued_at];
        })->all();
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }

    /** Issue PINs for a class (all studying students, only those without a PIN, or the listed ids). */
    public function issueClass(Request $r, int $classroomId): JsonResponse
    {
        $this->authorizeClass($classroomId);
        $d = $r->validate(['only_missing' => 'sometimes|boolean', 'student_ids' => 'sometimes|array|max:200', 'student_ids.*' => 'integer|min:1']);
        $students = $this->students($classroomId);
        if (isset($d['student_ids'])) $students = $students->filter(fn ($s) => in_array((int) $s->id, array_map('intval', $d['student_ids']), true))->values();
        if (!empty($d['only_missing'])) {
            $have = DB::table('api_student_pins')->where('school_id', Gateway::school())->whereIn('student_id', $students->pluck('id')->all() ?: [0])->pluck('student_id')->map(fn ($v) => (int) $v)->all();
            $students = $students->reject(fn ($s) => in_array((int) $s->id, $have, true))->values();
        }
        $name = DB::table('classrooms')->where('school_id', Gateway::school())->where('id', $classroomId)->value('name');
        $slips = DB::transaction(fn () => $students->map(fn ($s) => $this->issue($s))->all());
        return response()->json(['classroom' => ['id' => $classroomId, 'name' => $name], 'data' => $slips])->header('Cache-Control', 'no-store');
    }

    /** Reset one student's PIN and clear any lock. */
    public function resetStudent(int $studentId): JsonResponse
    {
        $s = DB::table('students')->where('school_id', Gateway::school())->where('id', $studentId)->first();
        if (!$s || !$s->classroom_id) abort(404);
        $this->authorizeClass((int) $s->classroom_id);
        $full = $this->students((int) $s->classroom_id)->firstWhere('id', $studentId);
        if (!$full) abort(422, 'นักเรียนคนนี้ไม่ได้อยู่ในสถานะกำลังศึกษา');
        $slip = DB::transaction(fn () => $this->issue($full));
        return response()->json(['data' => [$slip]])->header('Cache-Control', 'no-store');
    }

    /** @return array{student_id:int,code:string,display_name:string,pin:string} */
    private function issue(object $s): array
    {
        $pin = Pins::generate();
        $existing = DB::table('api_student_pins')->where('school_id', Gateway::school())->where('student_id', $s->id)->first();
        $values = ['pin_hash' => Pins::hash($pin, (int) $s->id), 'failed_count' => 0, 'locked_until' => null, 'issued_by' => auth()->id(), 'issued_at' => now()->toDateTimeString()];
        if (Schema::hasColumn('api_student_pins', 'lock_count')) $values['lock_count'] = 0;
        // F8 student portal: a slip PIN is temporary (must be changed at the first portal sign-in) and ends every portal session of the student
        if (Schema::hasColumn('api_student_pins', 'must_change')) $values['must_change'] = true;
        $detail = ['student_id' => (int) $s->id]; // never the PIN
        try {
            if ($existing) Mutation::update('api_student_pins', (int) $existing->id, (int) $existing->version, $values + (Schema::hasColumn('api_student_pins', 'auth_version') ? ['auth_version' => DB::raw('auth_version + 1')] : []), 'api_pin.reset', $detail);
            else Mutation::insert('api_student_pins', ['student_id' => (int) $s->id] + $values, 'api_pin.issue', $detail);
        } catch (\Throwable $e) {
            if (Net::isUniqueViolation($e)) abort(409, 'มีผู้ออก PIN ให้นักเรียนคนนี้พร้อมกัน กรุณาโหลดใหม่');
            throw $e;
        }
        // a reset usually means the PIN leaked: sessions it already produced must not outlive it
        DB::table('api_tokens')->where('user_kind', 'student')->where('subject_id', $s->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        // same rule as the student-portal reset: an e-mail bound while the old PIN was known stops being a way back in until it is verified again
        if (class_exists(\Saan\Modules\GuardianPortal\Contacts::class) && Schema::hasTable('gp_contacts')) \Saan\Modules\GuardianPortal\Contacts::revoke('student', (int) $s->id);
        return ['student_id' => (int) $s->id, 'code' => (string) $s->code, 'display_name' => Presenter::displayName($s), 'pin' => $pin];
    }

    private function students(int $classroomId)
    {
        $cols = ['id', 'code', 'name', 'classroom_id'];
        foreach (['prefix', 'first_name', 'last_name'] as $c) if (Schema::hasColumn('students', $c)) $cols[] = $c;
        $q = DB::table('students')->where('school_id', Gateway::school())->where('classroom_id', $classroomId);
        if (Schema::hasColumn('students', 'status')) $q->where('status', 'studying');
        return $q->orderBy('id')->get($cols);
    }

    private function authorizeClass(int $classroomId): void
    {
        $exists = DB::table('classrooms')->where('school_id', Gateway::school())->where('id', $classroomId)->exists();
        if (!$exists) abort(404);
        $ids = TeacherScope::homeroomIds();
        abort_unless($ids === null || in_array($classroomId, $ids, true), 403, 'ออก PIN ได้เฉพาะครูประจำชั้นของห้องนี้');
    }
}
