<?php

namespace Saan\Modules\Personnel\Console;

use App\Support\Saan\Mutation;
use App\Support\Saan\VersionConflict;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Saan\Modules\Personnel\Folders;
use Saan\Modules\Personnel\Support;

/**
 * saan:personnel-retention --dry-run | --apply
 * Archives active portfolio files older than their folder's retention_years: the bytes move to
 * personnel/<school>/_archive/<teacher>/<folder>/ (still private, still in the school:archive backup) and the row stays with
 * status 'archived'. Nothing is deleted. Folders without retention_years are never touched. Idempotent.
 *
 * Deployment (the core does not load module commands yet, see web-notices PruneCommand for the shared change):
 *   register the class from ModulesServiceProvider::boot() (console only) and add to routes/console.php
 *   Schedule::command('saan:personnel-retention --apply')->monthlyOn(1, '02:30')->withoutOverlapping();
 */
class RetentionCommand extends Command
{
    protected $signature = 'saan:personnel-retention {--dry-run : only report what would be archived} {--apply : move the files to the archive path}';
    protected $description = 'Archive portfolio files older than the retention period of their folder';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply === (bool) $this->option('dry-run')) { $this->error('[ERR] ระบุ --dry-run หรือ --apply อย่างใดอย่างหนึ่ง'); return self::INVALID; }
        Folders::ensure();
        $school = Support::school(); $disk = Support::disk();
        $totalFiles = 0; $totalBytes = 0; $failed = 0;
        foreach (DB::table('per_folders')->where('school_id', $school)->whereNotNull('retention_years')->where('retention_years', '>', 0)->orderBy('sort')->get() as $folder) {
            // by the document year (BE), not the upload time: historical files imported today are due now; one month grace for fresh uploads
            $lastYear = (int) now()->year + 543 - (int) $folder->retention_years;
            $files = 0; $bytes = 0;
            DB::table('per_files')->where('school_id', $school)->where('folder_id', $folder->id)->where('status', 'active')
                ->where('fiscal_or_school_year', '<=', $lastYear)->where('created_at', '<', now()->subMonth())
                ->orderBy('id')->chunkById(200, function ($rows) use ($apply, $disk, $school, &$files, &$bytes, &$failed) {
                    foreach ($rows as $f) {
                        if (!$apply) { $files++; $bytes += (int) $f->size; continue; }
                        $dest = 'personnel/'.$school.'/_archive/'.$f->teacher_id.'/'.$f->folder_id.'/'.basename($f->path);
                        if (!$disk->exists($f->path) || $disk->exists($dest) || !$disk->move($f->path, $dest)) { $failed++; $this->warn('[WARN] ข้ามไฟล์ #'.$f->id.' (ไม่พบไฟล์ต้นทางหรือย้ายไม่ได้)'); continue; }
                        try {
                            Mutation::update('per_files', (int) $f->id, (int) $f->version, ['status' => 'archived', 'path' => $dest, 'archived_at' => now()], 'personnel.file.archived', ['teacher_id' => (int) $f->teacher_id, 'size' => (int) $f->size]);
                            $files++; $bytes += (int) $f->size;
                        } catch (VersionConflict|\Throwable $e) {
                            $disk->move($dest, $f->path); // keep bytes and row consistent
                            $failed++; $this->warn('[WARN] ข้ามไฟล์ #'.$f->id.' ('.($e instanceof VersionConflict ? 'ถูกแก้ไขระหว่างทำงาน' : 'บันทึกไม่สำเร็จ').')');
                        }
                    }
                });
            if ($files || $failed) $this->line(sprintf('%s: %d ไฟล์ %s MB (เก็บ %d ปี)', $folder->label, $files, Support::mb($bytes), $folder->retention_years));
            $totalFiles += $files; $totalBytes += $bytes;
        }
        $this->info(sprintf('[OK] %s %d ไฟล์ %d bytes (%s MB)%s', $apply ? 'ย้ายเข้าที่เก็บถาวรแล้ว' : 'dry-run จะย้าย', $totalFiles, $totalBytes, Support::mb($totalBytes), $failed ? ' ข้าม '.$failed : ''));
        if ($apply && $totalFiles) Log::info('saan:personnel-retention', ['school_id' => $school, 'files' => $totalFiles, 'bytes' => $totalBytes]); // CLI has no audit actor
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
