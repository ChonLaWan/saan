<?php

namespace Saan\Modules\LeaveDuty\Http;

use App\Support\Saan\Idempotency;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Request;
use Saan\Modules\LeaveDuty\Leave;

/** /api/leave/*: thin HTTP layer. Role middleware is on the routes; ownership and approver rules live in Saan\Modules\LeaveDuty\Leave. */
class LeaveController
{
    public function __construct(private Leave $leave)
    {
    }

    private const MESSAGES = [
        'version.required' => 'ไม่พบเลขเวอร์ชันของข้อมูล กรุณาโหลดใหม่',
        'starts_on.required' => 'กรุณาเลือกวันเริ่มลา', 'ends_on.required' => 'กรุณาเลือกวันสิ้นสุด',
        'starts_on.date_format' => 'วันที่ไม่ถูกต้อง (รูปแบบ ปปปป-ดด-วว)', 'ends_on.date_format' => 'วันที่ไม่ถูกต้อง (รูปแบบ ปปปป-ดด-วว)',
        'reason.required' => 'กรุณาระบุเหตุผลการลา', 'reason.min' => 'เหตุผลการลาสั้นเกินไป', 'reason.max' => 'เหตุผลการลายาวเกิน 500 ตัวอักษร',
        'type.required' => 'กรุณาเลือกประเภทการลา', 'half_day.in' => 'ครึ่งวันต้องเป็นช่วงเช้าหรือช่วงบ่าย',
        'file.required' => 'กรุณาเลือกไฟล์', 'file.max' => 'ไฟล์ใหญ่เกินกำหนด', 'file.mimes' => 'รองรับเฉพาะไฟล์ PDF, JPG และ PNG',
    ];

    private function v(Request $r, array $rules): array
    {
        return $r->validate($rules, self::MESSAGES);
    }

    private function body(Request $r): array
    {
        return $this->v($r, ['type' => 'required|string|max:30', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d',
            'half_day' => 'nullable|in:am,pm', 'reason' => 'required|string|min:3|max:500']);
    }

    // ------------------------------------------------------------ types

    public function types(): Response
    {
        return response()->json(['data' => $this->leave->types()]);
    }

    private function typeRules(bool $create): array
    {
        return ($create ? ['code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,29}$/D']] : ['version' => 'required|integer|min:1'])
            + ['label' => 'required|string|min:2|max:120', 'yearly_quota_days' => 'nullable|numeric|min:0|max:999', 'needs_attachment_after_days' => 'nullable|numeric|min:0|max:99',
                'count_mode' => 'nullable|in:'.implode(',', Leave::COUNT_MODES)];
    }

    public function typeStore(Request $r): Response
    {
        return Idempotency::run($r, fn () => response()->json($this->leave->typeSave($this->v($r, $this->typeRules(true))), 201));
    }

    public function typeUpdate(Request $r, int $id): Response
    {
        return response()->json($this->leave->typeSave($this->v($r, $this->typeRules(false)), $id));
    }

    public function typeDestroy(Request $r, int $id): Response
    {
        $d = $this->v($r, ['version' => 'required|integer|min:1']);
        $this->leave->typeDelete($id, (int) $d['version']);
        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------ requests

    public function preview(Request $r): Response
    {
        $d = $this->v($r, ['type' => 'required|string|max:30', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d', 'half_day' => 'nullable|in:am,pm', 'except_id' => 'nullable|integer|min:1']);
        return response()->json($this->leave->preview($d, $r->user()));
    }

    public function index(Request $r): Response
    {
        $f = $this->v($r, ['scope' => 'nullable|in:mine,all', 'status' => 'nullable|in:draft,submitted,approved,rejected,withdrawn,cancel_requested,cancelled', 'fiscal_year' => 'nullable|integer|between:2500,2700',
            'teacher_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($this->leave->list($r->user(), $f['scope'] ?? 'mine', $f));
    }

    public function show(Request $r, int $id): Response
    {
        return response()->json($this->leave->read($id, $r->user()));
    }

    public function store(Request $r): Response
    {
        return Idempotency::run($r, fn () => response()->json($this->leave->create($this->body($r), $r->user()), 201));
    }

    public function update(Request $r, int $id): Response
    {
        $d = $this->body($r) + $this->v($r, ['version' => 'required|integer|min:1']);
        return response()->json($this->leave->update($id, $d, $r->user()));
    }

    public function destroy(Request $r, int $id): Response
    {
        $d = $this->v($r, ['version' => 'required|integer|min:1']);
        $this->leave->destroy($id, (int) $d['version'], $r->user());
        return response()->json(['ok' => true]);
    }

    public function transition(Request $r, int $id): Response
    {
        $d = $this->v($r, ['action' => 'required|in:'.implode(',', array_keys(Leave::ACTIONS)), 'version' => 'required|integer|min:1', 'reason' => 'nullable|string|max:500']);
        return Idempotency::run($r, fn () => response()->json($this->leave->transition($id, $d['action'], (int) $d['version'], $d['reason'] ?? null, $r->user())));
    }

    public function resubmit(Request $r, int $id): Response
    {
        $d = $this->body($r) + $this->v($r, ['version' => 'required|integer|min:1']);
        return Idempotency::run($r, fn () => response()->json($this->leave->resubmit($id, $d, $r->user()), 201));
    }

    // ------------------------------------------------------------ attachment

    public function attach(Request $r, int $id): Response
    {
        $d = $this->v($r, ['version' => 'required|integer|min:1', 'file' => 'required|file|max:'.Leave::maxKb().'|mimes:pdf,jpg,jpeg,png']);
        return response()->json($this->leave->attach($id, $r->file('file'), (int) $d['version'], $r->user()));
    }

    public function detach(Request $r, int $id): Response
    {
        $d = $this->v($r, ['version' => 'required|integer|min:1']);
        return response()->json($this->leave->detach($id, (int) $d['version'], $r->user()));
    }

    public function download(Request $r, int $id)
    {
        return $this->leave->download($id, $r->user());
    }

    // ------------------------------------------------------------ quota

    public function quota(Request $r): Response
    {
        $d = $this->v($r, ['fiscal_year' => 'nullable|integer|between:2500,2700', 'teacher_id' => 'nullable|integer|min:1']);
        $fy = (int) ($d['fiscal_year'] ?? Leave::fiscalYear(Leave::today()));
        return response()->json($this->leave->quota($r->user(), $fy, isset($d['teacher_id']) ? (int) $d['teacher_id'] : null) + ['fiscal_years' => $this->leave->fiscalYears()]);
    }

    public function report(Request $r): Response
    {
        $d = $this->v($r, ['fiscal_year' => 'nullable|integer|between:2500,2700']);
        return response()->json($this->leave->report((int) ($d['fiscal_year'] ?? Leave::fiscalYear(Leave::today()))) + ['fiscal_years' => $this->leave->fiscalYears()]);
    }
}
