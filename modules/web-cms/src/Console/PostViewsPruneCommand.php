<?php

namespace Saan\Modules\WebCms\Console;

use Illuminate\Console\Command;
use Saan\Modules\WebCms\PostViews;

/**
 * saan:post-views-prune
 * Retention of the news view counter: per-day rows older than saan.views.retention_days (default 90, at least 35) are folded into one total row per post
 * (internal and combined editions) or deleted once sent (public edition). Scheduled daily in routes/console.php, every edition.
 */
class PostViewsPruneCommand extends Command
{
    protected $signature = 'saan:post-views-prune';
    protected $description = 'Fold or prune per-day news view counts older than the retention window';

    public function handle(): int
    {
        $n = PostViews::prune();
        $this->line("[OK] retention ".PostViews::retentionDays()." days, removed $n rows");
        return self::SUCCESS;
    }
}
