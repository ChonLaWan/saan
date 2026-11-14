<?php

namespace Saan\Modules\Registry\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Saan\Modules\Registry\ImportService;

/** saan:registry-prune: wipes CSV import staging rows (children's data) after the preview and rollback windows. Idempotent. */
class RegistryPruneCommand extends Command
{
    protected $signature = 'saan:registry-prune {--preview-days=7 : keep never-committed previews this long} {--keep-days=30 : rollback window of committed batches} {--dry-run : only report}';
    protected $description = 'Delete registry import staging rows of old previews and of batches past the rollback window';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $r = ImportService::purge((int) $this->option('preview-days'), (int) $this->option('keep-days'), $dry);
        $this->line(($dry ? '[WARN] dry-run ' : '[OK] ').'reg_import: previews '.$r['preview_batches'].', archived '.$r['archived_batches'].', rows '.$r['rows_wiped']);
        if (!$dry && $r['rows_wiped'] > 0) Log::info('saan:registry-prune', $r);
        return self::SUCCESS;
    }
}
