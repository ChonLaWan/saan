<?php

namespace Saan\Modules\WebNotices\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * saan:notices-prune: deletes the rows (names) of notice lists that expired more than --days (default 30) ago,
 * clears their access code and archives the list shell (title and dates are kept for the audit trail).
 * Idempotent. Each pruned list is logged (Log::info 'saan:notices-prune') because CLI runs have no audit actor.
 *
 * Deployment (needs a core change, this module cannot do it safely itself):
 *  1. ModulesServiceProvider::boot() must register module commands independent of module activation and route cache:
 *     if ($this->app->runningInConsole()) foreach (glob(base_path('modules/*&#47;src/Console/*Command.php')) ?: [] as $f) {
 *         $id = basename(dirname($f, 3));
 *         $this->commands(['Saan\\Modules\\'.Str::studly($id).'\\Console\\'.basename($f, '.php')]);
 *     }
 *  2. routes/console.php: Schedule::command('saan:notices-prune')->dailyAt('03:15')->withoutOverlapping();
 *  3. Server cron: * * * * * php artisan schedule:run   (or run `php artisan saan:notices-prune` daily by hand).
 */
class PruneCommand extends Command
{
    protected $signature = 'saan:notices-prune {--days=30 : days after expiry before rows are deleted} {--dry-run : only report}';
    protected $description = 'Delete rows of public notice lists that expired more than N days ago';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $cutoff = CarbonImmutable::now('Asia/Bangkok')->subDays($days)->toDateString();
        $school = (int) config('school.school_id');
        $lists = DB::table('notice_lists')->where('school_id', $school)->where('expires_on', '<', $cutoff)->get(['id', 'slug', 'status']);
        $rowsDeleted = 0; $listsTouched = 0;
        foreach ($lists as $l) {
            $n = DB::table('notice_rows')->where('school_id', $school)->where('list_id', $l->id)->count();
            if ($n === 0 && $l->status === 'archived') continue;
            $listsTouched++; $rowsDeleted += $n;
            if ($this->option('dry-run')) { $this->line("[WARN] dry-run: {$l->slug} rows=$n"); continue; }
            DB::transaction(function () use ($l, $school) {
                DB::table('notice_rows')->where('school_id', $school)->where('list_id', $l->id)->delete();
                DB::table('notice_lists')->where('school_id', $school)->where('id', $l->id)->update(['status' => 'archived', 'access_code_hash' => null, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            });
            Log::info('saan:notices-prune', ['school_id' => $school, 'list_id' => $l->id, 'slug' => $l->slug, 'rows_deleted' => $n]);
        }
        $this->info("[OK] lists=$listsTouched rows_deleted=$rowsDeleted cutoff_expired_before=$cutoff".($this->option('dry-run') ? ' (dry-run)' : ''));
        return self::SUCCESS;
    }
}
