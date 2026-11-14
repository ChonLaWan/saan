<?php

namespace Saan\Modules\Attendance\Http;

use App\Support\Saan\Idempotency;
use App\Support\Saan\TeacherScope;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Saan\Modules\Attendance\Attendance;
use Saan\Modules\Attendance\SheetConflict;

class AttendanceController
{
    public function __construct(private Attendance $att) {}

    public function classes()
    {
        $rooms = $this->att->classrooms();
        $home = array_values(array_filter($rooms, fn ($r) => $r['homeroom']));
        return response()->json(['classrooms' => $rooms, 'default_classroom_id' => ($home[0] ?? $rooms[0] ?? null)['id'] ?? null, 'today' => $this->att->today()->toDateString(),
            'statuses' => Attendance::LABELS, 'edit_window_days' => $this->att->windowDays(), 'all_classrooms' => TeacherScope::classroomIds() === null]);
    }

    public function sheet(Request $r)
    {
        $d = $r->validate(['classroom_id' => 'required|integer|min:1', 'date' => 'required|date_format:Y-m-d']);
        return response()->json($this->att->sheet((int) $d['classroom_id'], $d['date']));
    }

    public function save(Request $r)
    {
        $d = $r->validate([
            'classroom_id' => 'required|integer|min:1', 'date' => 'required|date_format:Y-m-d', 'class_day_version' => 'required|integer|min:0',
            'rows' => 'present|array|max:200', 'rows.*' => 'array:student_id,status,version,note',
            'rows.*.student_id' => 'required|integer|min:1', 'rows.*.status' => ['required', Rule::in(Attendance::STATUSES)],
            'rows.*.version' => 'required|integer|min:0', 'rows.*.note' => 'nullable|string|max:200',
            'confirm' => 'sometimes|boolean', 'reason' => 'nullable|string|max:300', 'force' => 'sometimes|boolean',
        ]);
        try {
            return Idempotency::run($r, fn () => response()->json($this->att->save((int) $d['classroom_id'], $d['date'], (int) $d['class_day_version'], $d['rows'],
                (bool) ($d['confirm'] ?? false), $d['reason'] ?? null, (bool) ($d['force'] ?? false))));
        } catch (SheetConflict $e) {
            return response()->json($e->payload, 409);
        }
    }

    public function month(Request $r)
    {
        return response()->json($this->att->month(...$this->monthArgs($r)));
    }

    public function daily(Request $r)
    {
        $d = $r->validate(['date' => 'required|date_format:Y-m-d']);
        return response()->json($this->att->daily($d['date']));
    }

    public function student(Request $r, int $id)
    {
        $d = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        return response()->json($this->att->studentHistory($id, $d['from'] ?? null, $d['to'] ?? null));
    }

    public function export(Request $r)
    {
        $type = $r->validate(['type' => 'required|in:month,daily,student'])['type'];
        if ($type === 'month') { $m = $this->att->month(...$this->monthArgs($r)); $csv = $this->att->monthCsv($m); $name = 'attendance-month-'.$m['month'].'-class'.$m['classroom']['id']; }
        elseif ($type === 'daily') { $d = $r->validate(['date' => 'required|date_format:Y-m-d']); $csv = $this->att->dailyCsv($this->att->daily($d['date'])); $name = 'attendance-daily-'.$d['date']; }
        else {
            $d = $r->validate(['student_id' => 'required|integer|min:1', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
            $h = $this->att->studentHistory((int) $d['student_id'], $d['from'] ?? null, $d['to'] ?? null);
            $csv = $this->att->studentCsv($h); $name = 'attendance-student-'.$h['student']['id'].'-'.$h['from'].'-'.$h['to'];
        }
        // per-child sick/absent status and notes leave the system: leave a trace like the registry, health and exam exports
        \App\Support\Saan\Mutation::audit('attendance.exported', 'attendances', null, ['type' => $type, 'file' => $name, 'rows' => max(0, substr_count((string) $csv, "\n") - 1)]
            + ($type === 'student' ? ['student_id' => (int) $d['student_id']] : []) + ($type === 'month' ? ['classroom_id' => (int) $m['classroom']['id'], 'month' => $m['month']] : []));
        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'.csv"', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function monthArgs(Request $r): array
    {
        $d = $r->validate(['classroom_id' => 'required|integer|min:1', 'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/D']]);
        return [(int) $d['classroom_id'], $d['month']];
    }
}
