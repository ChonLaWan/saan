<?php

namespace Saan\Modules\Activities\Http;

use App\Support\Saan\Idempotency;
use Illuminate\Http\Request;
use Saan\Modules\Activities\Common;
use Saan\Modules\Activities\Elections;

/** /api/activities/elections|candidates: thin HTTP layer; secret-ballot rules live in Elections. */
class ElectionController
{
    private const MESSAGES = [
        'version.required' => 'ไม่พบเลขเวอร์ชันของข้อมูล กรุณาโหลดใหม่', 'title.required' => 'กรุณาระบุชื่อการเลือกตั้ง', 'opens_at.required' => 'กรุณาระบุเวลาเปิด', 'closes_at.required' => 'กรุณาระบุเวลาปิด',
        'number.required' => 'กรุณาระบุหมายเลขผู้สมัคร', 'student_id.required' => 'กรุณาเลือกนักเรียน', 'ticket.required' => 'คูหายังไม่ถูกปลดล็อก',
    ];

    public function __construct(private Elections $elections)
    {
    }

    private function v(Request $r, array $rules): array
    {
        return $r->validate($rules, self::MESSAGES);
    }

    public function index()
    {
        return response()->json(['data' => $this->elections->index(), 'role' => auth()->user()->role, 'manager' => Common::isManager()]);
    }

    public function store(Request $r)
    {
        $d = $this->v($r, ['title' => 'required|string|min:1|max:200', 'opens_at' => 'required|string|max:40', 'closes_at' => 'required|string|max:40',
            'eligible_grade_levels' => 'sometimes|nullable|array|max:20', 'eligible_grade_levels.*' => 'string|max:20']);
        return Idempotency::run($r, fn () => response()->json($this->elections->present($this->elections->create($d)), 201));
    }

    public function show(int $id)
    {
        $e = $this->elections->find($id);
        return response()->json(['election' => $this->elections->present($e), 'candidates' => $this->elections->candidates($id), 'manager' => Common::isManager()]);
    }

    public function update(Request $r, int $id)
    {
        $d = $this->v($r, ['version' => 'required|integer|min:1', 'title' => 'sometimes|string|min:1|max:200', 'opens_at' => 'sometimes|string|max:40', 'closes_at' => 'sometimes|string|max:40',
            'eligible_grade_levels' => 'sometimes|nullable|array|max:20', 'eligible_grade_levels.*' => 'string|max:20']);
        $version = (int) $d['version'];
        unset($d['version']);
        return response()->json($this->elections->present($this->elections->update($id, $version, $d)));
    }

    public function destroy(Request $r, int $id)
    {
        $d = $this->v($r, ['version' => 'required|integer|min:1']);
        $this->elections->destroy($id, (int) $d['version']);
        return response()->json(['deleted' => true]);
    }

    public function status(Request $r, int $id)
    {
        $d = $this->v($r, ['version' => 'required|integer|min:1', 'action' => 'required|in:open,close']);
        return response()->json($this->elections->present($this->elections->transition($id, (int) $d['version'], $d['action'])));
    }

    public function candidateStore(Request $r, int $id)
    {
        $d = $this->v($r, ['number' => 'required|integer|min:1|max:99', 'student_id' => 'required|integer|min:1', 'slogan' => 'nullable|string|max:300']);
        $k = $this->elections->addCandidate($id, (int) $d['number'], (int) $d['student_id'], $d['slogan'] ?? null);
        return response()->json(['id' => (int) $k->id, 'version' => (int) $k->version], 201);
    }

    public function candidateDestroy(Request $r, int $id)
    {
        $d = $this->v($r, ['version' => 'required|integer|min:1']);
        $this->elections->removeCandidate($id, (int) $d['version']);
        return response()->json(['deleted' => true]);
    }

    public function kiosk(int $id, int $classroomId)
    {
        return response()->json($this->elections->kiosk($id, $classroomId))->header('Cache-Control', 'no-store');
    }

    public function unlock(Request $r, int $id)
    {
        $d = $this->v($r, ['student_id' => 'required|integer|min:1']);
        return response()->json($this->elections->unlock($id, (int) $d['student_id']))->header('Cache-Control', 'no-store');
    }

    public function vote(Request $r, int $id)
    {
        $d = $this->v($r, ['ticket' => 'required|string|max:80', 'candidate_id' => 'present|nullable|integer|min:1']);
        return response()->json($this->elections->vote($id, $d['ticket'], $d['candidate_id'] === null ? null : (int) $d['candidate_id']), 201)->header('Cache-Control', 'no-store');
    }

    public function turnout(int $id)
    {
        return response()->json($this->elections->turnout($id))->header('Cache-Control', 'no-store');
    }

    public function results(int $id)
    {
        return response()->json($this->elections->results($id))->header('Cache-Control', 'no-store');
    }
}
