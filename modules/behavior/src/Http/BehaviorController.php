<?php

namespace Saan\Modules\Behavior\Http;

use App\Support\Saan\Idempotency;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Saan\Modules\Behavior\BehaviorRepository as Repo;

class BehaviorController
{
    public function __construct(private Repo $repo) {}

    private function termParam(Request $r): ?int
    {
        $v = $r->validate(['term_id' => 'nullable|integer|min:1']);
        return isset($v['term_id']) ? (int) $v['term_id'] : null;
    }

    public function context(Request $r)
    {
        return response()->json($this->repo->context($r->user()));
    }

    public function rules(Request $r)
    {
        $all = $r->boolean('all') && in_array($r->user()->role, ['admin', 'principal'], true);
        return response()->json(['data' => $this->repo->rules(!$all)]);
    }

    public function createRule(Request $r)
    {
        $d = $r->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/\S/u'], 'label' => ['required', 'string', 'max:200', 'regex:/\S/u'],
            'category' => 'required|in:'.implode(',', Repo::CATEGORIES), 'points' => 'required|integer|between:-'.Repo::MAX_POINTS.','.Repo::MAX_POINTS,
            'is_active' => 'sometimes|boolean',
        ]);
        return response()->json($this->repo->createRule($d), 201);
    }

    public function updateRule(Request $r, int $id)
    {
        $d = $r->validate([
            'version' => 'required|integer|min:1', 'label' => ['sometimes', 'string', 'max:200', 'regex:/\S/u'],
            'category' => 'sometimes|in:'.implode(',', Repo::CATEGORIES), 'points' => 'sometimes|integer|between:-'.Repo::MAX_POINTS.','.Repo::MAX_POINTS,
            'is_active' => 'sometimes|boolean',
        ]);
        return response()->json($this->repo->updateRule($id, $d));
    }

    public function store(Request $r)
    {
        $today = Carbon::now('Asia/Bangkok')->toDateString();
        $d = $r->validate([
            'student_ids' => 'required|array|min:1|max:'.Repo::MAX_STUDENTS, 'student_ids.*' => 'integer|min:1',
            'rule_id' => 'nullable|integer|min:1', 'points' => 'nullable|integer|between:-'.Repo::MAX_POINTS.','.Repo::MAX_POINTS,
            'occurred_on' => 'required|date_format:Y-m-d|before_or_equal:'.$today.'|after:'.Carbon::now('Asia/Bangkok')->subYear()->toDateString(),
            'note' => 'nullable|string|max:1000', 'guardian_visible' => 'sometimes|boolean',
        ]);
        if (empty($d['rule_id']) && empty($d['points'])) abort(422, 'เลือกกติกาหรือระบุคะแนน');
        $user = $r->user();
        return Idempotency::run($r, function () use ($d, $user) {
            $rows = $this->repo->record($d, $user);
            return response()->json(['count' => count($rows), 'term' => $this->repo->termInfo($rows[0]['term_id']), 'data' => $rows], 201);
        });
    }

    public function void(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'reason' => ['required', 'string', 'min:3', 'max:300', 'regex:/\S/u']]);
        return response()->json($this->repo->void($id, (int) $d['version'], $d['reason'], $r->user()));
    }

    public function timeline(Request $r, int $id)
    {
        return response()->json($this->repo->timeline($id, $this->termParam($r), $r->user()));
    }

    public function summary(Request $r, int $id)
    {
        return response()->json($this->repo->summary($id, $this->termParam($r), $r->user()));
    }

    public function report(Request $r)
    {
        return response()->json($this->repo->report($this->termParam($r)));
    }

    public function export(Request $r)
    {
        $v = $r->validate(['term_id' => 'nullable|integer|min:1', 'classroom_id' => 'nullable|integer|min:1']);
        $q = $this->repo->exportQuery($v['term_id'] ?? null, $v['classroom_id'] ?? null, $r->user()); // scope/permission errors surface here, before streaming starts
        \App\Support\Saan\Mutation::audit('behavior.exported', 'bhv_records', null, ['term_id' => $v['term_id'] ?? null, 'classroom_id' => $v['classroom_id'] ?? null, 'rows' => (clone $q)->count()]);
        // streamed in chunks: no row cap and no whole-result array in memory
        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['วันที่', 'รหัสนักเรียน', 'ชื่อ', 'ห้อง', 'รายการ', 'ประเภท', 'คะแนน', 'หมายเหตุ', 'สถานะ', 'เหตุผลที่ยกเลิก', 'ผู้บันทึก'], ',', '"', '');
            $q->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $row) fputcsv($out, array_map([$this, 'cell'], $this->repo->exportRow($row)), ',', '"', '');
            });
            fclose($out);
        }, 'behavior.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** Neutralise spreadsheet formulas in text cells; numbers pass through untouched. */
    private function cell(mixed $v): mixed
    {
        if (!is_string($v) || $v === '') return $v;
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
    }
}
