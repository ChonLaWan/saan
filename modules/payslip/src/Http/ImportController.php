<?php

namespace Saan\Modules\Payslip\Http;

use App\Support\Saan\Idempotency;
use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Saan\Modules\Payslip\Support;
use Saan\Modules\Payslip\Importer;
use Saan\Modules\Payslip\Pdfs;
use Saan\Modules\Payslip\Slips;
use Saan\Modules\Payslip\Vault;

/** Payroll officer: column mapping, the CSV/XLSX check screen and confirm, and per-person PDF upload. */
class ImportController
{
    private const NO_STORE = ['Cache-Control' => 'no-store, private'];

    public function mapping()
    {
        return response()->json(['mapping' => Importer::mapping()], 200, self::NO_STORE);
    }

    public function saveMapping(Request $r)
    {
        $r->validate(['mapping' => 'required|array']);
        $m = Importer::normaliseMapping($r->input('mapping'));
        Settings::put('payslip.import_map', $m);
        Mutation::audit('payslip.mapping.saved', 'saan_settings', null, ['key_type' => $m['key_type'], 'earnings' => count($m['earnings']), 'deductions' => count($m['deductions'])]);
        return response()->json(['mapping' => $m]);
    }

    /** Header row of a sample file, so the officer can pick columns once. Nothing is stored. */
    public function headers(Request $r)
    {
        $r->validate(['file' => 'required|file']);
        $f = Importer::read($r->file('file'));
        return response()->json(['headers' => $f['headers'], 'rows' => count($f['rows'])], 200, self::NO_STORE);
    }

    public function stage(Request $r, int $id)
    {
        $r->validate(['file' => 'required|file']);
        $period = Slips::period($id);
        $map = Importer::mapping() ?? abort(422, 'ตั้งค่าการจับคู่คอลัมน์ก่อนนำเข้า');
        return Idempotency::run($r, fn () => response()->json(Importer::stage($period, $r->file('file'), $map), 201),
            fn () => response()->json(Importer::checkView(Importer::latest($id)), 201));
    }

    public function show(int $id)
    {
        return response()->json(Importer::checkView(Importer::find($id)), 200, self::NO_STORE);
    }

    public function confirm(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        $imp = Importer::find($id);
        return Idempotency::run($r, fn () => response()->json(Importer::checkView(Importer::confirm($imp, (int) $d['version']))),
            fn () => response()->json(Importer::checkView(Importer::find($id))));
    }

    /** One PDF per person; the payee code is the file name (T001.pdf). Each file is reported on its own, a bad file never stops the others. */
    public function pdfs(Request $r, int $id)
    {
        $r->validate(['files' => 'required|array|min:1|max:60', 'files.*' => 'file']);
        $period = Slips::period($id);
        abort_unless($period->status === 'draft', 422, 'อัปโหลด PDF ได้เฉพาะงวดที่ยังเป็นฉบับร่าง');
        return Idempotency::run($r, function () use ($r, $period) {
            $out = []; $stored = 0;
            foreach ($r->file('files') as $file) {
                $name = mb_substr(basename(str_replace('\\', '/', (string) $file->getClientOriginalName())), 0, 120);
                $err = Pdfs::problem($file);
                if ($err !== null) { $out[] = ['name' => $name, 'result' => 'refused', 'message' => $err]; continue; }
                $payee = Pdfs::payeeFor($name);
                if (!$payee) { $out[] = ['name' => $name, 'result' => 'unmatched', 'message' => 'ไม่พบผู้รับเงินที่รหัสตรงกับชื่อไฟล์']; continue; }
                if (Slips::isOwn((int) $payee->id)) { $out[] = ['name' => $name, 'result' => 'refused', 'message' => 'สลิปของบัญชีตัวเองอัปโหลดไม่ได้ ให้เจ้าหน้าที่อีกคนทำให้']; continue; }
                $path = Vault::putFile((string) file_get_contents((string) $file->getRealPath()));
                try {
                    // the status read above can be stale (publish / cancel race): lock the period row and re-check it with the write
                    DB::transaction(function () use ($period, $payee, $path, $file) {
                        $live = DB::table('psl_periods')->where('school_id', Support::school())->where('id', (int) $period->id)->lockForUpdate()->first();
                        abort_unless($live && $live->status === 'draft', 422, 'อัปโหลด PDF ได้เฉพาะงวดที่ยังเป็นฉบับร่าง');
                        Slips::create($live, (int) $payee->id, null, $path, (int) $file->getSize());
                    });
                    $stored++;
                    $out[] = ['name' => $name, 'result' => 'stored', 'payee_code' => (string) $payee->payee_code];
                } catch (\Throwable $e) {
                    Vault::forget($path);
                    if (method_exists($e, 'getStatusCode') && $e->getStatusCode() === 409) { $out[] = ['name' => $name, 'result' => 'duplicate', 'message' => $e->getMessage()]; continue; }
                    throw $e;
                }
            }
            Slips::recompute((int) $period->id);
            return response()->json(['stored' => $stored, 'files' => $out], 201);
        }, function () use ($period) { // file names are usually people's names and payee codes: not cached; a replay reports the period state instead
            $n = DB::table('psl_slips')->where('school_id', Support::school())->where('period_id', (int) $period->id)->where('status', 'active')->where('kind', 'pdf')->count();
            return response()->json(['stored' => $n, 'files' => [], 'replayed' => true], 201);
        });
    }
}
