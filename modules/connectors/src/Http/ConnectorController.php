<?php

namespace Saan\Modules\Connectors\Http;

use App\Support\Saan\Idempotency;
use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Saan\Modules\Connectors\ArchiveService;
use Saan\Modules\Connectors\MappingService;
use Saan\Modules\Connectors\Presets;

class ConnectorController
{
    public function __construct(private ArchiveService $archives, private MappingService $mapping) {}

    private function upload(Request $r): array
    {
        $r->validate(['file' => 'required|file']);
        $file = $r->file('file');
        abort_unless($file->isValid(), 422, 'อัปโหลดไฟล์ไม่สำเร็จ');
        abort_if($file->getSize() > max(1, (int) config('saan.storage.max_upload_mb')) * 1048576, 422, 'ไฟล์ใหญ่เกินกำหนด');
        return [(string) file_get_contents($file->getRealPath()), $file->getClientOriginalName()];
    }

    /** optional JSON object {original header: canonical field | "ignore"} */
    private function customMapping(Request $r): array
    {
        $raw = $r->input('mapping');
        if ($raw === null || $raw === '') return [];
        $m = is_array($raw) ? $raw : json_decode((string) $raw, true);
        abort_unless(is_array($m) && count($m) <= 200, 422, 'รูปแบบการจับคู่คอลัมน์ไม่ถูกต้อง');
        foreach ($m as $h => $f) abort_unless(is_string($f) && mb_strlen((string) $h) <= 120 && mb_strlen($f) <= 40, 422, 'รูปแบบการจับคู่คอลัมน์ไม่ถูกต้อง');
        return $m;
    }

    // ---------- shared ----------

    public function config()
    {
        $u = auth()->user();
        return response()->json(['role' => $u->role, 'can_manage' => ArchiveService::isManager($u), 'is_admin' => $u->role === 'admin', 'teacher_lookup' => ArchiveService::teacherLookupOn(),
            'kinds' => ArchiveService::KIND_LABEL, 'registry_available' => MappingService::registryAvailable()]);
    }

    public function search(Request $r)
    {
        $d = $r->validate(['q' => 'required|string|max:80']);
        return response()->json($this->archives->search($d['q']))->header('Cache-Control', 'no-store, private');
    }

    public function record(Request $r)
    {
        $d = $r->validate(['archive_id' => 'required|integer|min:1', 'code' => 'required|string|max:40']);
        return response()->json($this->archives->record((int) $d['archive_id'], $d['code']))->header('Cache-Control', 'no-store, private');
    }

    public function export(Request $r)
    {
        $d = $r->validate(['archive_id' => 'nullable|integer|min:1', 'code' => 'required|string|max:40']);
        $x = $this->archives->exportCsv(isset($d['archive_id']) ? (int) $d['archive_id'] : null, $d['code']);
        return response($x['csv'], 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$x['filename'].'"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    // ---------- management (admin, principal) ----------

    public function presets() { return response()->json(['data' => Presets::listing(), 'fields' => Presets::FIELDS]); }

    public function index() { return response()->json(['data' => $this->archives->archives()]); }

    public function show(int $id) { return response()->json($this->archives->present($this->archives->find($id))); }

    public function store(Request $r)
    {
        $d = $r->validate(['label' => 'required|string|max:120', 'year_be' => 'required|integer|between:2400,2700', 'source' => 'nullable|string|max:200']);
        return Idempotency::run($r, fn () => response()->json($this->archives->create($d['label'], (int) $d['year_be'], $d['source'] ?? null), 201));
    }

    public function update(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'label' => 'sometimes|string|max:120', 'year_be' => 'sometimes|integer|between:2400,2700',
            'source' => 'sometimes|nullable|string|max:200', 'locked' => 'sometimes|boolean']);
        return response()->json($this->archives->update($id, (int) $d['version'], $d, auth()->user()->role === 'admin'));
    }

    public function destroy(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'confirm' => 'nullable|string|max:200']);
        $this->archives->delete($id, (int) $d['version'], (string) ($d['confirm'] ?? ''));
        return response()->json(['ok' => true]);
    }

    public function import(Request $r, int $id)
    {
        $d = $r->validate(['kind' => ['required', Rule::in(ArchiveService::KINDS)], 'preset' => ['required', Rule::in(Presets::keys())]]);
        [$bytes, $name] = $this->upload($r);
        $custom = $this->customMapping($r);
        return Idempotency::run($r, fn () => response()->json($this->archives->importFile($id, $d['kind'], $d['preset'], $custom, $name, $bytes), 201));
    }

    public function setSettings(Request $r)
    {
        $d = $r->validate(['teacher_lookup' => 'required|boolean']);
        $new = (bool) $d['teacher_lookup'];
        if ($new !== ArchiveService::teacherLookupOn()) {
            Settings::put('connectors.teacher_lookup', $new);
            Mutation::audit('connectors.settings.updated', 'saan_settings', null, ['teacher_lookup' => $new]);
        }
        return response()->json(['teacher_lookup' => $new]);
    }

    // ---------- mapping importer ----------

    public function mappingPreview(Request $r)
    {
        $d = $r->validate(['preset' => ['required', Rule::in(Presets::keys())]]);
        [$bytes] = $this->upload($r);
        return response()->json($this->mapping->preview($bytes, $d['preset'], $this->customMapping($r)));
    }

    public function mappingCsv(Request $r)
    {
        $d = $r->validate(['preset' => ['required', Rule::in(Presets::keys())]]);
        [$bytes] = $this->upload($r);
        $csv = $this->mapping->toCsv($this->mapping->map($bytes, $d['preset'], $this->customMapping($r)));
        Mutation::audit('connectors.mapping.csv', 'con_mapping_runs', null, ['preset' => $d['preset']]);
        return response("\xEF\xBB\xBF".$csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="registry-students-mapped.csv"', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function mappingRuns() { return response()->json(['data' => $this->mapping->runs()]); }

    public function mappingRun(int $id) { return response()->json($this->mapping->run($id)); }

    public function mappingStage(Request $r)
    {
        $d = $r->validate(['preset' => ['required', Rule::in(Presets::keys())]]);
        [$bytes, $name] = $this->upload($r);
        $custom = $this->customMapping($r);
        return Idempotency::run($r, fn () => response()->json($this->mapping->stage($bytes, $name, $d['preset'], $custom), 201));
    }

    public function mappingCommit(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        return Idempotency::run($r, fn () => response()->json($this->mapping->commit($id, (int) $d['version'])));
    }
}
