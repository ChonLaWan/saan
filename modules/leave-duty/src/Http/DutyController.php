<?php

namespace Saan\Modules\LeaveDuty\Http;

use App\Support\Saan\Idempotency;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Request;
use Saan\Modules\LeaveDuty\Duty;

/** /api/duty/*: thin HTTP layer. Roster writes are admin/principal (route middleware); swap rules live in Saan\Modules\LeaveDuty\Duty. */
class DutyController
{
    public function __construct(private Duty $duty)
    {
    }

    private const MESSAGES = [
        'version.required' => 'ไม่พบเลขเวอร์ชันของข้อมูล กรุณาโหลดใหม่',
        'date.required' => 'กรุณาเลือกวันที่', 'date.date_format' => 'วันที่ไม่ถูกต้อง (รูปแบบ ปปปป-ดด-วว)',
        'starts_at.date_format' => 'เวลาเริ่มไม่ถูกต้อง (HH:MM)', 'ends_at.date_format' => 'เวลาสิ้นสุดไม่ถูกต้อง (HH:MM)',
        'location.required' => 'กรุณาระบุจุดเวร', 'teacher_id.required' => 'กรุณาเลือกครู', 'kind.in' => 'ประเภทเวรไม่ถูกต้อง',
        'reason.required' => 'กรุณาระบุเหตุผล',
    ];

    private function v(Request $r, array $rules): array
    {
        return $r->validate($rules, self::MESSAGES);
    }

    private function body(Request $r): array
    {
        return $this->v($r, ['teacher_id' => 'required|integer|min:1', 'date' => 'required|date_format:Y-m-d', 'starts_at' => 'required|date_format:H:i', 'ends_at' => 'required|date_format:H:i',
            'location' => 'required|string|min:1|max:120', 'kind' => 'nullable|in:'.implode(',', Duty::KINDS)]);
    }

    public function teachers(): Response
    {
        return response()->json(['data' => $this->duty->teachers()]);
    }

    public function index(Request $r): Response
    {
        $f = $this->v($r, ['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'teacher_id' => 'nullable|integer|min:1']);
        return response()->json($this->duty->list($r->user(), $f));
    }

    public function store(Request $r): Response
    {
        return Idempotency::run($r, fn () => response()->json($this->duty->create($this->body($r)), 201));
    }

    public function update(Request $r, int $id): Response
    {
        return response()->json($this->duty->update($id, $this->body($r) + $this->v($r, ['version' => 'required|integer|min:1'])));
    }

    public function destroy(Request $r, int $id): Response
    {
        $d = $this->v($r, ['version' => 'required|integer|min:1']);
        $this->duty->destroy($id, (int) $d['version']);
        return response()->json(['ok' => true]);
    }

    public function cancel(Request $r, int $id): Response
    {
        $d = $this->v($r, ['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:300', 'replaced_by_teacher_id' => 'nullable|integer|min:1']);
        return response()->json($this->duty->cancel($id, (int) $d['version'], $d['reason'], isset($d['replaced_by_teacher_id']) ? (int) $d['replaced_by_teacher_id'] : null));
    }

    public function swaps(Request $r): Response
    {
        $d = $this->v($r, ['scope' => 'nullable|in:mine,approval,all', 'status' => 'nullable|in:requested,accepted,declined,approved,rejected,cancelled']);
        return response()->json($this->duty->swaps($r->user(), $d['scope'] ?? 'mine', $d['status'] ?? null));
    }

    public function swapStore(Request $r, int $id): Response
    {
        $d = $this->v($r, ['to_teacher_id' => 'required|integer|min:1', 'note' => 'nullable|string|max:300']);
        return Idempotency::run($r, fn () => response()->json($this->duty->swapRequest($id, (int) $d['to_teacher_id'], $d['note'] ?? null, $r->user()), 201));
    }

    public function swapTransition(Request $r, int $id): Response
    {
        $d = $this->v($r, ['action' => 'required|in:'.implode(',', array_keys(Duty::SWAP_ACTIONS)), 'version' => 'required|integer|min:1', 'reason' => 'nullable|string|max:300']);
        return Idempotency::run($r, fn () => response()->json($this->duty->swapTransition($id, $d['action'], (int) $d['version'], $d['reason'] ?? null, $r->user())));
    }
}
