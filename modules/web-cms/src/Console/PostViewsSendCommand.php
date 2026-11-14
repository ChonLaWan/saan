<?php

namespace Saan\Modules\WebCms\Console;

use App\Support\Saan\Edition;
use App\Support\Saan\Inbox\InboxRejected;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Saan\Modules\WebCms\PostViews;

/**
 * saan:post-views-send
 * Public edition: sends the per-day view totals of news posts that changed since the last run to the internal edition as sealed inbox envelopes of
 * kind post_views (post id, day, count; nothing about a visitor, see Saan\Modules\WebCms\PostViews). Other editions have nothing to send.
 * Scheduled every 10 minutes in routes/console.php (public edition only).
 */
class PostViewsSendCommand extends Command
{
    protected $signature = 'saan:post-views-send';
    protected $description = 'Send news view counts (aggregate) from the public edition to the internal edition';

    public function handle(): int
    {
        if (!Edition::isPublic()) { $this->line('[OK] not the public edition: nothing to send'); return self::SUCCESS; }
        try {
            $n = PostViews::flush();
        } catch (InboxRejected $e) {
            Log::warning('[WARN] news view counts not sent: '.$e->getMessage());
            $this->warn('[WARN] not sent, will retry: '.$e->getMessage());
            return self::SUCCESS;
        }
        $this->line("[OK] sent $n rows");
        return self::SUCCESS;
    }
}
