<?php

namespace Saan\Modules\Finance\Console;

use App\Support\Saan\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Saan\Modules\Finance\Slips;

/**
 * saan:finance-prune: retention of payment slip files. Slips of claims that were decided (approved, rejected, withdrawn) more than N years ago
 * are deleted from the private disk and their rows removed; the claim row, payment and receipt stay. Pending claims are never touched.
 * N = --years, else Settings finance.slip_retention_years (default 5); 0 keeps slips forever.
 * CLI runs have no signed-in actor, so Mutation would skip the audit row; every pruned claim is written to Log::info instead (ids only).
 * Scheduled in routes/console.php like the other prune commands.
 */
class PruneCommand extends Command
{
    protected $signature = 'saan:finance-prune {--years= : years after the decision before the slip files are deleted} {--dry-run : only report}';
    protected $description = 'Delete payment slip files of claims decided more than N years ago';

    public function handle(): int
    {
        $school = (int) config('school.school_id');
        $years = $this->option('years') !== null && $this->option('years') !== '' ? (int) $this->option('years') : (int) Settings::get('finance.slip_retention_years', 5);
        if ($years <= 0) { $this->info('[OK] retention disabled (years=0), nothing deleted'); return self::SUCCESS; }
        $cutoff = now()->subYears($years)->format('Y-m-d H:i:s');
        $claims = DB::table('fin_payment_claims')->where('school_id', $school)->whereIn('status', ['approved', 'rejected', 'withdrawn', 'voided'])
            ->whereRaw('COALESCE(decided_at, updated_at) < ?', [$cutoff])->whereExists(fn ($q) => $q->selectRaw('1')->from('fin_claim_slips')->whereColumn('fin_claim_slips.claim_id', 'fin_payment_claims.id'))
            ->orderBy('id')->pluck('id');
        $files = 0;
        foreach ($claims as $id) {
            $slips = DB::table('fin_claim_slips')->where('school_id', $school)->where('claim_id', $id)->get(['id', 'path']);
            if ($this->option('dry-run')) { $this->line("[WARN] dry-run: claim $id slips={$slips->count()}"); $files += $slips->count(); continue; }
            DB::table('fin_claim_slips')->where('school_id', $school)->where('claim_id', $id)->delete();
            foreach ($slips as $s) Slips::forget($s->path);
            Log::info('saan:finance-prune', ['school_id' => $school, 'claim_id' => $id, 'files' => $slips->count()]);
            $files += $slips->count();
        }
        $this->info('[OK] claims='.count($claims)." files_removed=$files cutoff_decided_before=$cutoff".($this->option('dry-run') ? ' (dry-run)' : ''));
        return self::SUCCESS;
    }
}
