<?php

namespace Saan\Modules\Surveys\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * saan:surveys-prune: deletes expired verification rows (name + answers) from srv_pending of every survey, open or closed.
 * Rows whose link was already used (response_id set) are kept until they expire, so a used link still shows its result.
 * Scheduled daily in routes/console.php.
 */
class PruneCommand extends Command
{
    protected $signature = 'saan:surveys-prune {--dry-run : only report}';
    protected $description = 'Delete expired pending survey verifications';

    public function handle(): int
    {
        $q = DB::table('srv_pending')->where('expires_at', '<', now());
        $n = (clone $q)->count();
        if (!$this->option('dry-run') && $n > 0) {
            $q->delete();
            Log::info('saan:surveys-prune', ['pending_deleted' => $n]);
        }
        // ip_hash only serves the hourly anonymous budget: wipe it after two days (and at close, see SurveyAdminController::status).
        $old = DB::table('srv_responses')->where('ip_hash', '!=', '')->where('created_at', '<', now()->subDays(2));
        if (!$this->option('dry-run')) $old->update(['ip_hash' => '']);
        $this->info("[OK] pending_deleted=$n".($this->option('dry-run') ? ' (dry-run)' : ''));
        return self::SUCCESS;
    }
}
