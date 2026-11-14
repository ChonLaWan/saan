<?php

namespace Saan\Modules\WebNotices\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Saan\Modules\WebNotices\NoticeRepository;

/**
 * saan:notices-public-prune: public-side defence in depth for full-name notice lists (F9). Every pub_notice_rows row whose full_name_until has
 * passed gets its name masked in place (first_initial) and full_name_until cleared; unreadable rows are deleted. Runs every minute on the public
 * edition (routes/console.php), independent of the internal side, so a database snapshot taken after full_name_until holds no full name even
 * when the internal side is down and no masked batch arrived. Idempotent; the receiver and the renderer apply the same rule on their own.
 */
class PublicPruneCommand extends Command
{
    protected $signature = 'saan:notices-public-prune';
    protected $description = 'Mask names of public notice rows whose full-name window has passed';

    public function handle(): int
    {
        $n = NoticeRepository::maskExpiredPublicRows();
        if ($n > 0) Log::info('saan:notices-public-prune', ['rows_masked' => $n]);
        $this->info("[OK] rows_masked=$n");
        return self::SUCCESS;
    }
}
