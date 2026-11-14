<?php

namespace Saan\Modules\Payslip;

use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use App\Support\Saan\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly import (CSV or XLSX) with a column mapping the payroll officer saves once (Settings payslip.import_map).
 *
 * Mapping: {key_type: code|national_id, key_col, earnings: [{col, name}], deductions: [{col, name}], tax_col, sso_col, pf_col, net_col}. Columns are file headers.
 * Row rules (first match wins): unmatched (no payee for the key) | duplicate (the key appears twice in the file, or the payee already has a live slip in the period)
 * | invalid (an amount is not a plain non-negative number) | mismatch (net != earnings - deductions) | ok. Only ok rows are ever stored; the others are listed to the officer.
 * The check screen (stage) keeps the accepted rows encrypted in psl_imports; confirm turns them into slips of a DRAFT period. A national id typed in the file is only used to
 * look up the payee by keyed hash; problems show the id masked.
 */
final class Importer
{
    public const MAX_BYTES = 5242880;
    public const MAX_ROWS = 2000;
    public const MAX_SHOWN = 100;

    public static function mapping(): ?array
    {
        $m = Settings::get('payslip.import_map');
        return is_array($m) ? $m : null;
    }

    private static function bad(string $msg): never { throw ValidationException::withMessages(['mapping' => [$msg]]); }

    /** Validates and normalises a mapping from the request. */
    public static function normaliseMapping(array $in): array
    {
        $col = function (mixed $v, string $what, bool $required): ?string {
            $s = trim((string) preg_replace('/\s+/u', ' ', (string) $v));
            if ($s === '') { if ($required) self::bad('กรุณาเลือกคอลัมน์'.$what); return null; }
            if (mb_strlen($s) > 120) self::bad('ชื่อคอลัมน์'.$what.'ยาวเกิน 120 ตัวอักษร');
            return $s;
        };
        $keyType = ($in['key_type'] ?? '') === 'national_id' ? 'national_id' : (($in['key_type'] ?? '') === 'code' ? 'code' : self::bad('ชนิดคอลัมน์ผู้รับต้องเป็น code หรือ national_id'));
        $out = ['key_type' => $keyType, 'key_col' => $col($in['key_col'] ?? '', 'ผู้รับเงิน', true)];
        $used = [self::hk($out['key_col']) => true];
        $claim = function (?string $c) use (&$used) { if ($c === null) return; $k = self::hk($c); if (isset($used[$k])) self::bad('คอลัมน์ "'.$c.'" ถูกใช้ซ้ำ'); $used[$k] = true; };
        foreach (['earnings', 'deductions'] as $group) {
            $list = $in[$group] ?? [];
            if (!is_array($list) || !array_is_list($list) || count($list) > Slips::MAX_ITEMS) self::bad('รายการ'.($group === 'earnings' ? 'รายรับ' : 'รายการหัก').'มีได้ไม่เกิน '.Slips::MAX_ITEMS.' รายการ');
            $out[$group] = [];
            foreach ($list as $it) {
                $c = $col(is_array($it) ? ($it['col'] ?? '') : '', 'ของรายการ', true);
                $name = trim((string) preg_replace('/\s+/u', ' ', (string) (is_array($it) ? ($it['name'] ?? '') : ''))) ?: $c;
                if (mb_strlen($name) > 80) self::bad('ชื่อรายการยาวเกิน 80 ตัวอักษร');
                $claim($c);
                $out[$group][] = ['col' => $c, 'name' => $name];
            }
        }
        if (!$out['earnings']) self::bad('ต้องมีคอลัมน์รายรับอย่างน้อยหนึ่งรายการ');
        foreach (['tax_col' => 'ภาษีที่หัก', 'sso_col' => 'ประกันสังคม', 'pf_col' => 'กองทุนสำรองเลี้ยงชีพ'] as $k => $label) { $out[$k] = $col($in[$k] ?? '', $label, false); $claim($out[$k]); }
        $out['net_col'] = $col($in['net_col'] ?? '', 'ยอดสุทธิ', true);
        $claim($out['net_col']);
        return $out;
    }

    private static function hk(string $h): string { return preg_replace('/[\s_\-.()\x{FEFF}]+/u', '', mb_strtolower(trim($h))); }

    // ---------------------------------------------------------------- reading the file

    /** @return array{headers: list<string>, rows: list<array{line:int,cells:list<string>}>} */
    public static function read(UploadedFile $file): array
    {
        abort_unless($file->isValid(), 422, 'อัปโหลดไฟล์ไม่สำเร็จ');
        $size = (int) $file->getSize();
        abort_if($size < 1, 422, 'ไฟล์ว่างเปล่า');
        abort_if($size > self::MAX_BYTES, 422, 'ไฟล์ใหญ่เกิน '.round(self::MAX_BYTES / 1048576).' MB');
        $bytes = (string) file_get_contents((string) $file->getRealPath());
        $lines = [];
        if (SpreadsheetReader::isXlsx($bytes)) {
            foreach (SpreadsheetReader::rows($bytes) as $i => $cells) $lines[] = [$i + 1, array_map(fn ($c) => trim((string) $c), $cells)];
        } else {
            if (str_starts_with($bytes, "\xEF\xBB\xBF")) $bytes = substr($bytes, 3);
            abort_if(!mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0"), 422, 'ไฟล์ต้องเป็น CSV (UTF-8) หรือ XLSX');
            $bytes = str_replace(["\r\n", "\r"], "\n", $bytes);
            $first = strtok($bytes, "\n") ?: '';
            $delim = ',';
            foreach ([';', "\t"] as $d) if (substr_count($first, $d) > substr_count($first, $delim)) $delim = $d;
            $h = fopen('php://temp', 'r+');
            fwrite($h, $bytes); rewind($h);
            $n = 0;
            while (($r = fgetcsv($h, 0, $delim, '"', '')) !== false) { $n++; $lines[] = [$n, array_map(fn ($c) => trim((string) $c), $r === [null] ? [] : $r)]; }
            fclose($h);
        }
        $headers = null; $rows = [];
        foreach ($lines as [$no, $cells]) {
            if (!array_filter($cells, fn ($c) => $c !== '')) continue;
            if ($headers === null) { $headers = $cells; continue; }
            $rows[] = ['line' => $no, 'cells' => $cells];
        }
        abort_if($headers === null, 422, 'ไฟล์ไม่มีหัวตาราง');
        abort_if(count($rows) > self::MAX_ROWS, 422, 'ไฟล์มีแถวเกิน '.self::MAX_ROWS.' แถว กรุณาแบ่งไฟล์');
        return ['headers' => $headers, 'rows' => $rows];
    }

    // ---------------------------------------------------------------- the check screen

    /** Parses, matches and checks every row, stores the accepted ones encrypted and returns what the check screen shows. */
    public static function stage(object $period, UploadedFile $file, array $map): array
    {
        abort_unless($period->status === 'draft', 422, 'นำเข้าได้เฉพาะงวดที่ยังเป็นฉบับร่าง');
        $sha = hash_file('sha256', (string) $file->getRealPath());
        $f = self::read($file);
        $idx = [];
        foreach ($f['headers'] as $i => $h) $idx[self::hk($h)] ??= $i;
        $need = array_merge([$map['key_col'], $map['net_col']], array_column($map['earnings'], 'col'), array_column($map['deductions'], 'col'), array_filter([$map['tax_col'], $map['sso_col'], $map['pf_col']]));
        $missing = array_values(array_filter($need, fn ($c) => !isset($idx[self::hk($c)])));
        if ($missing) throw ValidationException::withMessages(['file' => ['ไฟล์ไม่มีคอลัมน์ที่ตั้งไว้: '.implode(', ', $missing).' (ตรวจการจับคู่คอลัมน์)']]);
        $at = fn (array $cells, string $col) => (string) ($cells[$idx[self::hk($col)]] ?? '');

        // pass 1: normalised key per row, and how often each key occurs
        $keys = []; $seen = [];
        foreach ($f['rows'] as $i => $row) {
            $raw = $at($row['cells'], $map['key_col']);
            $k = $map['key_type'] === 'national_id' ? Payees::normaliseNid($raw) : Payees::codeKey($raw);
            $keys[$i] = [$raw, $k];
            if ($k !== '') $seen[$k] = ($seen[$k] ?? 0) + 1;
        }
        $live = DB::table('psl_slips')->where('school_id', Support::school())->where('period_id', (int) $period->id)->where('status', 'active')->pluck('payee_id')->map(fn ($v) => (int) $v)->flip()->all();

        $counts = ['rows' => 0, 'matched' => 0, 'unmatched' => 0, 'duplicated' => 0, 'refused' => 0, 'accepted' => 0];
        $totals = ['earnings' => 0, 'deductions' => 0, 'net' => 0];
        $accepted = []; $problems = []; $cache = [];
        foreach ($f['rows'] as $i => $row) {
            [$raw, $k] = $keys[$i];
            if ($k === '') continue; // blank key = blank line
            $counts['rows']++;
            $shown = $map['key_type'] === 'national_id' ? Payees::maskNid($k) : mb_substr($raw, 0, 40);
            $problem = function (string $reason) use (&$problems, $row, $shown) { $problems[] = ['line' => $row['line'], 'key' => $shown, 'reason' => $reason]; };
            $payee = $cache[$k] ??= ($map['key_type'] === 'national_id' ? Payees::byNid($k) : Payees::byCode($raw)) ?: false;
            if (!$payee) { $counts['unmatched']++; $problem('unmatched'); continue; }
            $counts['matched']++;
            if (Slips::isOwn((int) $payee->id)) { $counts['refused']++; $problem('own_payee'); continue; }
            if ($seen[$k] > 1 || isset($live[(int) $payee->id])) { $counts['duplicated']++; $problem($seen[$k] > 1 ? 'duplicate_in_file' : 'duplicate_existing'); continue; }
            $items = fn (array $defs) => array_map(fn ($d) => ['name' => $d['name'], 'amount' => $at($row['cells'], $d['col'])], $defs);
            $b = Slips::build($items($map['earnings']), $items($map['deductions']),
                $map['tax_col'] ? $at($row['cells'], $map['tax_col']) : 0, $map['sso_col'] ? $at($row['cells'], $map['sso_col']) : 0, $map['pf_col'] ? $at($row['cells'], $map['pf_col']) : 0, $at($row['cells'], $map['net_col']));
            if (!$b['ok']) { $counts['refused']++; $problem($b['error']); continue; }
            $counts['accepted']++;
            $totals['earnings'] += $b['data']['gross']; $totals['deductions'] += $b['data']['ded']; $totals['net'] += $b['data']['net'];
            $accepted[] = ['payee_id' => (int) $payee->id, 'line' => $row['line'], 'data' => $b['data']];
        }
        $payload = ['counts' => $counts, 'totals' => $totals, 'problems' => array_slice($problems, 0, 500), 'accepted' => $accepted];
        // an older staged import of this period is superseded
        DB::table('psl_imports')->where('school_id', Support::school())->where('period_id', (int) $period->id)->where('status', 'staged')->delete();
        $imp = Mutation::insert('psl_imports', ['period_id' => (int) $period->id, 'created_by' => (int) auth()->id(), 'status' => 'staged', 'rows_total' => $counts['rows'], 'rows_ok' => $counts['accepted'],
            'payload_enc' => Vault::sealJson($payload), 'file_sha256' => $sha], 'payslip.import.staged', ['period_id' => (int) $period->id, 'rows' => $counts['rows'], 'accepted' => $counts['accepted']]);
        return self::checkView($imp, $payload);
    }

    public static function checkView(object $imp, ?array $payload = null): array
    {
        $payload ??= Vault::openJson($imp->payload_enc) ?? [];
        return ['import_id' => (int) $imp->id, 'status' => $imp->status, 'period_id' => (int) $imp->period_id, 'version' => (int) $imp->version, 'counts' => $payload['counts'] ?? [], 'totals' => $payload['totals'] ?? [],
            'problems' => array_slice($payload['problems'] ?? [], 0, self::MAX_SHOWN), 'problems_total' => count($payload['problems'] ?? []),
            'can_confirm' => $imp->status === 'staged' && (int) ($payload['counts']['accepted'] ?? 0) > 0];
    }

    public static function find(int $id): object
    {
        return DB::table('psl_imports')->where('school_id', Support::school())->where('id', $id)->first() ?? abort(404, 'ไม่พบรายการนำเข้า');
    }

    public static function latest(int $periodId): object
    {
        return DB::table('psl_imports')->where('school_id', Support::school())->where('period_id', $periodId)->orderByDesc('id')->first() ?? abort(404, 'ไม่พบรายการนำเข้า');
    }

    /** Turns the accepted rows into slips. All or nothing: a payee that got a slip meanwhile (409) cancels the whole confirm. */
    public static function confirm(object $imp, int $version): object
    {
        abort_unless($imp->status === 'staged', 422, 'รายการนำเข้านี้ยืนยันไปแล้ว');
        $payload = Vault::openJson($imp->payload_enc) ?? abort(422, 'อ่านข้อมูลนำเข้าไม่ได้ กรุณาอัปโหลดใหม่');
        abort_if(empty($payload['accepted']), 422, 'ไม่มีแถวที่ผ่านการตรวจ');
        $period = Slips::period((int) $imp->period_id);
        abort_unless($period->status === 'draft', 422, 'นำเข้าได้เฉพาะงวดที่ยังเป็นฉบับร่าง');
        return DB::transaction(function () use ($imp, $version, $payload, $period) {
            $period = DB::table('psl_periods')->where('school_id', Support::school())->where('id', (int) $period->id)->lockForUpdate()->first();
            abort_unless($period && $period->status === 'draft', 422, 'นำเข้าได้เฉพาะงวดที่ยังเป็นฉบับร่าง');
            foreach ($payload['accepted'] as $a) {
                $payee = Payees::find((int) $a['payee_id']) ?? abort(409, 'ผู้รับเงินบางรายถูกลบไประหว่างตรวจ กรุณาอัปโหลดใหม่');
                Slips::create($period, (int) $payee->id, Slips::withBank($a['data'], $payee), null);
            }
            $keep = ['counts' => $payload['counts'], 'totals' => $payload['totals'], 'problems' => $payload['problems']];
            $row = Mutation::update('psl_imports', (int) $imp->id, $version, ['status' => 'confirmed', 'payload_enc' => Vault::sealJson($keep)], 'payslip.import.confirmed',
                ['period_id' => (int) $imp->period_id, 'slips' => count($payload['accepted'])]);
            Slips::recompute((int) $imp->period_id);
            return $row;
        });
    }
}
