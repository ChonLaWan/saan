<?php

namespace Saan\Modules\Maintenance\Console;

use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use App\Support\Saan\VersionConflict;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * saan:maintenance-prune: PDPA retention for repair photos. Removes the photo file of requests closed (done, rejected, cancelled)
 * more than N months ago and clears photo_path/photo_size/photo_name/photo_mime through Mutation::update (version bump).
 * N = --months, else Settings maintenance.photo_retention_months (default 12); 0 keeps photos forever.
 * CLI runs have no signed-in actor, so Mutation skips the audit row; every pruned request is written to Log::info instead.
 * Scheduling is an explicit routes/console.php decision: Schedule::command('saan:maintenance-prune')->dailyAt('03:50')->withoutOverlapping();
 */
class PruneCommand extends Command
{
    protected $signature = 'saan:maintenance-prune {--months= : months after closing before the photo is deleted} {--dry-run : only report}';
    protected $description = 'Delete photos of repair requests that were closed more than N months ago';

    public function handle(): int
    {
        $school = (int) config('school.school_id');
        $months = $this->option('months') !== null && $this->option('months') !== '' ? (int) $this->option('months') : (int) Settings::get('maintenance.photo_retention_months', 12);
        if ($months <= 0) { $this->info('[OK] retention disabled (months=0), nothing deleted'); return self::SUCCESS; }
        $cutoff = now()->subMonthsNoOverflow($months)->format('Y-m-d H:i:s');
        $disk = Storage::disk('local');
        $n = 0; $skipped = 0;
        $rows = DB::table('mnt_requests')->where('school_id', $school)->whereNotNull('photo_path')->whereIn('status', ['done', 'rejected', 'cancelled'])
            ->whereNotNull('closed_at')->where('closed_at', '<', $cutoff)->orderBy('id')->get(['id', 'photo_path', 'version']);
        foreach ($rows as $r) {
            if ($this->option('dry-run')) { $this->line("[WARN] dry-run: request {$r->id}"); $n++; continue; }
            try {
                Mutation::update('mnt_requests', (int) $r->id, (int) $r->version, ['photo_path' => null, 'photo_size' => null, 'photo_name' => null, 'photo_mime' => null],
                    'maintenance.request.photo_pruned', ['months' => $months]);
            } catch (VersionConflict $e) { $skipped++; continue; }
            if (str_starts_with((string) $r->photo_path, 'maintenance/')) $disk->delete($r->photo_path);
            Log::info('saan:maintenance-prune', ['school_id' => $school, 'request_id' => $r->id]);
            $n++;
        }
        $this->info("[OK] photos_removed=$n skipped=$skipped cutoff_closed_before=$cutoff".($this->option('dry-run') ? ' (dry-run)' : ''));
        return self::SUCCESS;
    }
}
