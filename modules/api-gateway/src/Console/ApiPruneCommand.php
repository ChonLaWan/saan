<?php

namespace Saan\Modules\ApiGateway\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * saan:api-prune: deletes api_request_log rows older than --days (default 30), tokens expired or revoked more than 7 days ago,
 * and SSO codes expired more than a day ago. Idempotent. Not scheduled by the module itself: see README.th.md
 * (Schedule::command('saan:api-prune')->dailyAt('03:30')->withoutOverlapping() in routes/console.php, or run it from cron).
 */
class ApiPruneCommand extends Command
{
    protected $signature = 'saan:api-prune {--days=30 : keep this many days of request log} {--dry-run : only report}';
    protected $description = 'Delete old API request log rows, dead tokens and used or expired SSO codes';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $logCut = $now->subDays(max(1, (int) $this->option('days')));
        $tokenCut = $now->subDays(7);
        $codeCut = $now->subDay();
        $jobs = [
            'api_request_log' => DB::table('api_request_log')->where('at', '<', $logCut),
            'api_tokens' => DB::table('api_tokens')->where(fn ($q) => $q->where('expires_at', '<', $tokenCut)->orWhere('revoked_at', '<', $tokenCut)),
            'api_sso_codes' => DB::table('api_sso_codes')->where('expires_at', '<', $codeCut),
        ];
        foreach ($jobs as $table => $query) {
            $n = $this->option('dry-run') ? $query->count() : $query->delete();
            $this->line(($this->option('dry-run') ? '[WARN] dry-run ' : '[OK] ')."$table: $n");
            if (!$this->option('dry-run') && $n > 0) Log::info('saan:api-prune', ['table' => $table, 'deleted' => $n]);
        }
        return self::SUCCESS;
    }
}
