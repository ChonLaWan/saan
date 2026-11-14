<?php

namespace Saan\Modules\Connectors;

use App\Support\Saan\Mutation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maps a legacy student CSV to the header layout that the registry import accepts, then hands it to the registry module's own
 * ImportService (preview + commit + rollback stay in the registry). This module never writes students directly.
 */
class MappingService
{
    public const MAX_ROWS = 3000; // same limit as the registry importer
    private const OUT = ['code', 'prefix', 'first_name', 'last_name', 'display_name', 'sex', 'birth_date', 'classroom', 'roll_number'];

    public static function registryAvailable(): bool
    {
        return class_exists(\Saan\Modules\Registry\ImportService::class) && app(\App\Support\Modules\ModuleRegistry::class)->active('registry') && Schema::hasTable('reg_import_batches') && Schema::hasTable('reg_import_rows');
    }

    private function registry(): \Saan\Modules\Registry\ImportService
    {
        abort_unless(self::registryAvailable(), 422, 'ยังไม่ได้เปิดใช้โมดูลทะเบียนนักเรียน จึงนำเข้าโดยตรงไม่ได้ ใช้ไฟล์ CSV ที่แปลงแล้วแทน');
        return app(\Saan\Modules\Registry\ImportService::class);
    }

    /** @return array{rows: array<int,array<string,string>>, lines: array<int,int>, issues: array<int,array<int,string>>, map: array<int,string>, unmapped: array<int,string>, headers: array<int,string>} */
    public function map(string $bytes, string $preset, array $custom): array
    {
        abort_unless(in_array($preset, Presets::keys(), true), 422, 'รูปแบบไฟล์ไม่ถูกต้อง');
        $csv = LegacyCsv::parse($bytes);
        abort_if(count($csv['rows']) > self::MAX_ROWS, 422, 'นำเข้าได้ครั้งละไม่เกิน '.self::MAX_ROWS.' แถว');
        [$map, $unmapped] = Presets::mapHeaders($csv['headers'], $preset, $custom);
        abort_unless(in_array('code', $map, true), 422, 'ไม่พบคอลัมน์รหัสนักเรียน (เลขประจำตัวนักเรียน) ตั้งการจับคู่คอลัมน์เอง');
        abort_unless(array_intersect(['first_name', 'full_name'], $map), 422, 'ไม่พบคอลัมน์ชื่อนักเรียน');
        $rooms = [];
        foreach (DB::table('classrooms')->where('school_id', ArchiveService::school())->pluck('name') as $n) $rooms[$this->roomKey((string) $n)] = true;
        $rows = []; $issues = [];
        foreach ($csv['rows'] as $i => $cells) {
            $r = [];
            foreach ($map as $c => $f) $r[$f] = (string) ($cells[$c] ?? '');
            $out = ['code' => trim($r['code'] ?? ''), 'prefix' => trim($r['prefix'] ?? ''), 'first_name' => trim($r['first_name'] ?? ''), 'last_name' => trim($r['last_name'] ?? ''),
                'display_name' => trim($r['first_name'] ?? '') !== '' ? '' : trim($r['full_name'] ?? ''), 'sex' => '', 'birth_date' => '', 'classroom' => Presets::classroom($r), 'roll_number' => trim($r['roll_number'] ?? '')];
            $p = [];
            if (($v = trim($r['sex'] ?? '')) !== '') { $s = Presets::sex($v); $s ? $out['sex'] = $s : $p[] = 'เพศไม่ถูกต้อง: '.$v; }
            if (($v = trim($r['birth_date'] ?? '')) !== '') $out['birth_date'] = preg_match('/^\d{8}$/D', $v) ? substr($v, 0, 4).'-'.substr($v, 4, 2).'-'.substr($v, 6, 2) : $v;
            if ($out['code'] === '') $p[] = 'ไม่มีรหัสนักเรียน';
            if ($out['first_name'] === '' && $out['display_name'] === '') $p[] = 'ไม่มีชื่อ';
            if ($out['classroom'] !== '' && !isset($rooms[$this->roomKey($out['classroom'])])) $p[] = 'ไม่พบห้องเรียนในระบบ: '.$out['classroom'];
            $rows[] = $out; $issues[] = $p;
        }
        return ['rows' => $rows, 'lines' => $csv['lines'], 'issues' => $issues, 'map' => $map, 'unmapped' => array_values($unmapped), 'headers' => $csv['headers']];
    }

    private function roomKey(string $n): string { return mb_strtolower(preg_replace('/\s+/u', '', trim($n))); }

    /** $protect: neutralise spreadsheet formulas (downloads only); the registry hand-off stays raw so codes and names are stored unchanged. */
    public function toCsv(array $mapped, bool $protect = true): string
    {
        $w = $protect ? [LegacyCsv::class, 'line'] : [LegacyCsv::class, 'rawLine'];
        $out = $w(self::OUT);
        foreach ($mapped['rows'] as $r) $out .= $w(array_map(fn ($h) => $r[$h], self::OUT));
        return $out;
    }

    public function preview(string $bytes, string $preset, array $custom): array
    {
        $m = $this->map($bytes, $preset, $custom);
        $bad = count(array_filter($m['issues']));
        $sample = [];
        foreach (array_slice($m['rows'], 0, 20, true) as $i => $r) $sample[] = ['line' => $m['lines'][$i], 'data' => $r, 'issues' => $m['issues'][$i]];
        return ['summary' => ['rows' => count($m['rows']), 'with_issues' => $bad, 'registry_available' => self::registryAvailable()],
            'mapping' => array_map(fn ($c, $f) => ['column' => $m['headers'][$c], 'field' => $f], array_keys($m['map']), $m['map']),
            'unmapped_columns' => $m['unmapped'], 'sample' => $sample];
    }

    /** Creates the registry import batch (preview only; nothing is committed) and remembers it as a run of this module. */
    public function stage(string $bytes, string $filename, string $preset, array $custom): array
    {
        $registry = $this->registry();
        $m = $this->map($bytes, $preset, $custom);
        $csv = $this->toCsv($m, false);
        $result = $registry->preview('students', 'connectors-'.mb_substr(basename(str_replace('\\', '/', $filename)), 0, 150), $csv);
        $batchId = (int) $result['batch']['id'];
        $run = Mutation::insert('con_mapping_runs', ['registry_batch_id' => $batchId, 'filename' => mb_substr(basename(str_replace('\\', '/', $filename)), 0, 200), 'preset' => $preset,
            'row_count' => count($m['rows']), 'status' => 'previewed', 'created_by' => auth()->id()], 'connectors.mapping.staged', ['registry_batch_id' => $batchId, 'preset' => $preset, 'rows' => count($m['rows'])]);
        return ['run' => $this->presentRun($run), 'registry' => $result];
    }

    public function runs(): array
    {
        return DB::table('con_mapping_runs')->where('school_id', ArchiveService::school())->orderByDesc('id')->limit(20)->get()->map(fn ($r) => $this->presentRun($r))->all();
    }

    public function run(int $id): array
    {
        $r = DB::table('con_mapping_runs')->where('school_id', ArchiveService::school())->where('id', $id)->first() ?? abort(404, 'ไม่พบรายการแปลงข้อมูล');
        $out = ['run' => $this->presentRun($r)];
        if (self::registryAvailable()) $out['registry'] = $this->registry()->batch((int) $r->registry_batch_id);
        return $out;
    }

    public function commit(int $id, int $version): array
    {
        $r = DB::table('con_mapping_runs')->where('school_id', ArchiveService::school())->where('id', $id)->first() ?? abort(404, 'ไม่พบรายการแปลงข้อมูล');
        abort_if($r->status !== 'previewed', 409, 'รายการนี้ถูกนำเข้าไปแล้ว');
        $registry = $this->registry();
        return DB::transaction(function () use ($id, $version, $r, $registry) {
            // claim first (version check -> 409); a failed registry commit rolls the claim back with it
            Mutation::update('con_mapping_runs', $id, $version, ['status' => 'committed'], 'connectors.mapping.committed', ['registry_batch_id' => (int) $r->registry_batch_id]);
            $batch = $registry->commit((int) $r->registry_batch_id);
            return ['run' => $this->presentRun(DB::table('con_mapping_runs')->where('id', $id)->first()), 'registry' => $batch];
        });
    }

    private function presentRun(object $r): array
    {
        return ['id' => (int) $r->id, 'registry_batch_id' => (int) $r->registry_batch_id, 'filename' => $r->filename, 'preset' => $r->preset, 'row_count' => (int) $r->row_count,
            'status' => $r->status, 'version' => (int) $r->version, 'created_at' => $r->created_at];
    }
}
