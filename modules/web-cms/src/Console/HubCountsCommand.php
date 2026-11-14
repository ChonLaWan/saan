<?php

namespace Saan\Modules\WebCms\Console;

use App\Support\Saan\Edition;
use App\Support\Saan\Inbox\InboxRejected;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Saan\Modules\WebCms\Downloads;

/**
 * saan:hubs-send-counts
 * Public edition: sends the per-day download totals of hub documents that changed since the last run to the internal edition as sealed inbox
 * envelopes of kind hub_downloads (aggregate numbers only, see Saan\Modules\WebCms\Downloads). Other editions have nothing to send.
 * Scheduled every 10 minutes in routes/console.php (public edition only).
 */
class HubCountsCommand extends Command
{
    protected $signature = 'saan:hubs-send-counts';
    protected $description = 'Send hub document download counts (aggregate) from the public edition to the internal edition';

    public function handle(): int
    {
        if (!Edition::isPublic()) { $this->line('[OK] not the public edition: nothing to send'); return self::SUCCESS; }
        try {
            $n = Downloads::flush();
            $pruned = Downloads::prune();
        } catch (InboxRejected $e) {
            Log::warning('[WARN] hub download counts not sent: '.$e->getMessage());
            $this->warn('[WARN] not sent, will retry: '.$e->getMessage());
            return self::SUCCESS;
        }
        $this->line("[OK] sent $n rows, pruned $pruned");
        return self::SUCCESS;
    }
}
