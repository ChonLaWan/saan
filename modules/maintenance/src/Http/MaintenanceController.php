<?php

namespace Saan\Modules\Maintenance\Http;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Support\Saan\Idempotency;
use Saan\Modules\Maintenance\Maintenance;

class MaintenanceController
{
    public function __construct(private Maintenance $m) {}

    private const SATANG = 'nullable|integer|min:0|max:'.Maintenance::MAX_SATANG;

    public function meta() { return response()->json($this->m->meta()); }

    public function technicians() { return response()->json($this->m->technicians()); }

    public function setTechnicians(Request $r)
    {
        $d = $r->validate(['version' => 'required|integer|min:0', 'technician_user_ids' => 'present|array|max:200', 'technician_user_ids.*' => 'integer|min:1',
            'photo_retention_months' => 'nullable|integer|min:0|max:120']);
        return response()->json($this->m->setTechnicians($d['technician_user_ids'], (int) $d['version'], isset($d['photo_retention_months']) ? (int) $d['photo_retention_months'] : null));
    }

    public function teachers() { return response()->json($this->m->teachers()); }

    // ---- requests
    public function requests(Request $r)
    {
        $p = $r->validate(['scope' => ['nullable', Rule::in(['mine', 'all'])], 'status' => ['nullable', Rule::in(array_merge(array_keys(Maintenance::STATUS), ['open', 'closed']))],
            'urgency' => ['nullable', Rule::in(array_keys(Maintenance::URGENCY))], 'assigned' => ['nullable', Rule::in(['me'])], 'asset_id' => 'nullable|integer|min:1',
            'q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1|max:100000', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($this->m->listRequests($p));
    }

    public function createRequest(Request $r)
    {
        $d = $r->validate(['asset_id' => 'nullable|integer|min:1', 'location' => 'nullable|string|max:160', 'title' => 'required|string|min:2|max:200', 'detail' => 'nullable|string|max:3000',
            'urgency' => ['nullable', Rule::in(array_keys(Maintenance::URGENCY))], 'photo' => 'nullable|file']);
        return Idempotency::run($r, fn () => response()->json($this->m->create($d, $r->file('photo')), 201));
    }

    public function showRequest(int $id) { return response()->json($this->m->show($id)); }

    public function updateRequest(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'title' => 'sometimes|string|min:2|max:200', 'detail' => 'sometimes|nullable|string|max:3000',
            'location' => 'sometimes|string|min:1|max:160', 'urgency' => ['sometimes', Rule::in(array_keys(Maintenance::URGENCY))]]);
        $v = (int) $d['version']; unset($d['version']);
        return response()->json($this->m->update($id, $v, $d));
    }

    public function transition(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'to' => ['required', Rule::in(array_keys(Maintenance::STATUS))], 'note' => 'nullable|string|max:1000',
            'resolution' => 'nullable|string|max:3000', 'cost_satang' => self::SATANG]);
        return Idempotency::run($r, fn () => response()->json($this->m->transition($id, (int) $d['version'], $d['to'], $d)));
    }

    public function assign(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'user_id' => 'present|nullable|integer|min:1']);
        return response()->json($this->m->assign($id, (int) $d['version'], $d['user_id'] === null ? null : (int) $d['user_id']));
    }

    public function photo(int $id) { return $this->m->photo($id); }

    // ---- assets
    private function assetRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        return ['asset_code' => $req.'|string|min:1|max:80', 'name' => $req.'|string|min:1|max:200', 'category' => 'sometimes|nullable|string|max:80', 'location' => 'sometimes|nullable|string|max:160',
            'acquired_on' => 'sometimes|nullable|date_format:Y-m-d', 'price_satang' => 'sometimes|'.self::SATANG, 'responsible_teacher_id' => 'sometimes|nullable|integer|min:1',
            'status' => ['sometimes', Rule::in(array_keys(Maintenance::ASSET_STATUS))]];
    }

    public function lookup(Request $r)
    {
        $d = $r->validate(['q' => 'required|string|min:1|max:80']);
        return response()->json(['data' => $this->m->lookupAssets($d['q'])]);
    }

    public function assets(Request $r)
    {
        $p = $r->validate(['q' => 'nullable|string|max:100', 'category' => 'nullable|string|max:80', 'status' => ['nullable', Rule::in(array_keys(Maintenance::ASSET_STATUS))],
            'page' => 'nullable|integer|min:1|max:100000', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($this->m->listAssets($p));
    }

    public function showAsset(int $id) { return response()->json($this->m->showAsset($id)); }

    public function createAsset(Request $r)
    {
        $d = $r->validate($this->assetRules(true));
        return Idempotency::run($r, fn () => response()->json($this->m->createAsset($d), 201));
    }

    public function updateAsset(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1'] + $this->assetRules(false));
        $v = (int) $d['version']; unset($d['version']);
        return response()->json($this->m->updateAsset($id, $v, $d));
    }

    public function deleteAsset(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        $this->m->deleteAsset($id, (int) $d['version']);
        return response()->json(['ok' => true]);
    }

    // ---- stock
    public function stock(Request $r)
    {
        $p = $r->validate(['q' => 'nullable|string|max:100', 'below' => 'nullable|boolean', 'page' => 'nullable|integer|min:1|max:100000', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($this->m->listStock($p));
    }

    public function createItem(Request $r)
    {
        $d = $r->validate(['code' => 'required|string|min:1|max:60', 'name' => 'required|string|min:1|max:200', 'unit' => 'required|string|min:1|max:30',
            'reorder_level' => 'nullable|integer|min:0|max:1000000', 'initial_qty' => 'nullable|integer|min:0|max:1000000']);
        return Idempotency::run($r, fn () => response()->json($this->m->createItem($d), 201));
    }

    public function updateItem(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'code' => 'sometimes|string|min:1|max:60', 'name' => 'sometimes|string|min:1|max:200',
            'unit' => 'sometimes|string|min:1|max:30', 'reorder_level' => 'sometimes|integer|min:0|max:1000000']);
        $v = (int) $d['version']; unset($d['version']);
        return response()->json($this->m->updateItem($id, $v, $d));
    }

    public function moves(Request $r, int $id)
    {
        $p = $r->validate(['page' => 'nullable|integer|min:1|max:100000', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($this->m->moves($id, $p));
    }

    public function move(Request $r, int $id)
    {
        $d = $r->validate(['kind' => ['required', Rule::in(array_keys(Maintenance::MOVE_KINDS))], 'qty' => 'required|integer|min:-1000000|max:1000000', 'ref' => 'nullable|string|max:120', 'note' => 'nullable|string|max:300']);
        if ($d['kind'] === 'adjust') abort_if((int) $d['qty'] === 0, 422, 'จำนวนที่ปรับต้องไม่เป็นศูนย์');
        else abort_if((int) $d['qty'] < 1, 422, 'จำนวนต้องมากกว่าศูนย์');
        return Idempotency::run($r, fn () => response()->json($this->m->move($id, $d), 201));
    }

    // ---- reports, export, labels
    public function openByAge() { return response()->json($this->m->openByAge()); }

    public function costByMonth(Request $r)
    {
        $d = $r->validate(['year' => 'nullable|integer|min:2000|max:2100']);
        return response()->json($this->m->costByMonth((int) ($d['year'] ?? substr(Maintenance::today(), 0, 4))));
    }

    public function stockBelow() { return response()->json($this->m->stockBelow()); }

    public function export(string $what)
    {
        abort_unless(in_array($what, ['requests', 'assets', 'stock', 'moves'], true), 404);
        [$file, $head, $rows] = $this->m->exportRows($what);
        return response()->streamDownload(function () use ($head, $rows) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF"); // BOM so Excel reads Thai as UTF-8
            fputcsv($h, array_map([Maintenance::class, 'cell'], $head), ',', '"', '');
            foreach ($rows as $row) fputcsv($h, array_map([Maintenance::class, 'cell'], $row), ',', '"', '');
            fclose($h);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function labels(Request $r)
    {
        $d = $r->validate(['ids' => 'nullable|string|max:4000', 'category' => 'nullable|string|max:80']);
        $ids = isset($d['ids']) ? array_values(array_filter(array_map('intval', explode(',', $d['ids'])))) : [];
        return response(view('maintenance::labels', $this->m->labelData(['ids' => $ids, 'category' => $d['category'] ?? null]))->render())
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex');
    }
}
