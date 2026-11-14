<?php

namespace Saan\Modules\Payslip\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Saan\Modules\Payslip\Support;
use Saan\Modules\Payslip\Vault;

/**
 * saan:payslip-prune: retention of payroll records. Periods whose pay date is more than N years ago are deleted with their slips (rows and encrypted PDFs)
 * and their imports. Certificates of years older than the cutoff year are removed too. Staged imports older than 2 days are dropped (they hold encrypted amounts that were never confirmed). Payees are never pruned.
 * N = --years, else Settings payslip.retention_years (default 10); 0 keeps everything. CLI runs have no signed-in actor, so ids go to Log::info (no amounts).
 */
class PruneCommand extends Command
{
    protected $signature = 'saan:payslip-prune {--years= : years after the pay date before slips are deleted} {--dry-run : only report}';
    protected $description = 'Delete payslips and 50 tawi certificates older than the retention period';

    public function handle(): int
    {
        $school = Support::school();
        $years = $this->option('years') !== null && $this->option('years') !== '' ? (int) $this->option('years') : Support::retentionYears();
        $dry = (bool) $this->option('dry-run');
        $stale = DB::table('psl_imports')->where('school_id', $school)->where('status', 'staged')->where('created_at', '<', now()->subDays(2)); // confirmed rows are the record of what was imported: kept with their period
        $imports = (clone $stale)->count();
        if (!$dry) $stale->delete();
        if ($years <= 0) { $this->info("[OK] retention disabled (years=0), periods untouched; stale imports removed=$imports"); return self::SUCCESS; }
        // whole calendar years only: a 50 tawi covers one calendar year, so a year is deleted together with its certificate or not at all (a year cut in the middle would leave a certificate that no longer matches its slips)
        $cutoff = substr(now('Asia/Bangkok')->subYears($years)->toDateString(), 0, 4).'-01-01';
        $periods = DB::table('psl_periods')->where('school_id', $school)->where('pay_date', '<', $cutoff)->orderBy('id')->pluck('id');
        $slips = 0; $files = 0;
        foreach ($periods as $pid) {
            $rows = DB::table('psl_slips')->where('school_id', $school)->where('period_id', $pid)->get(['id', 'file_path']);
            $slips += $rows->count();
            if ($dry) { $this->line("[WARN] dry-run: period $pid slips={$rows->count()}"); continue; }
            DB::transaction(function () use ($school, $pid) {
                DB::table('psl_slips')->where('school_id', $school)->where('period_id', $pid)->delete();
                DB::table('psl_imports')->where('school_id', $school)->where('period_id', $pid)->delete();
                DB::table('psl_periods')->where('school_id', $school)->where('id', $pid)->delete();
            });
            foreach ($rows as $r) if ($r->file_path) { Vault::forget($r->file_path); $files++; }
            Log::info('saan:payslip-prune', ['school_id' => $school, 'period_id' => $pid, 'slips' => $rows->count()]);
        }
        $certYear = (int) substr($cutoff, 0, 4);
        $certs = DB::table('psl_certs')->where('school_id', $school)->where('year', '<', $certYear);
        $certCount = (clone $certs)->count();
        if (!$dry) $certs->delete();
        $this->info('[OK] periods='.count($periods)." slips=$slips files_removed=$files certificates=$certCount stale_imports=$imports cutoff_pay_date_before=$cutoff".($dry ? ' (dry-run)' : ''));
        return self::SUCCESS;
    }
}
