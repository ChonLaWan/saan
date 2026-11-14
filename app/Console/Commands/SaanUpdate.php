<?php
namespace App\Console\Commands;

use App\Domain\Updater;
use Illuminate\Console\Command;

class SaanUpdate extends Command
{
    protected $signature = 'saan:update {package? : Path of a signed saan-core-*.zip or saan-mod-*.zip} {--recover : Restore the snapshot of an interrupted update and bring the site up} {--recover-dir= : With --recover: the update folder (directly under storage/app/private/updates) to restore instead of the newest unfinished one} {--completed : With --recover-dir: also take back an update that already finished (saan:update --both uses this for the public app)} {--auto : With --recover: do nothing and stay silent when no update is unfinished or one is still running (used by the scheduler)} {--finish= : Internal: run the steps after the file swap for this update folder (started by the updater on the new release)} {--all= : Folder of a release: install its core and module packages in dependency order, each in a process of its own} {--check : Verify signature, files and requirements only} {--skip-db-backup : Do not run school:archive first (not recommended)} {--both : Standard install: update the internal app, then the public app (path from storage/installed.json); roll back both when either fails} {--php= : PHP CLI binary used for the public app with --both (default: the one running this command)}';
    protected $description = 'Install a signed Saan core or module package with backup, maintenance mode, migration, health check and automatic rollback';

    public function handle(): int
    {
        $updater = new Updater(fn ($line) => $this->line($line));
        try {
            // started by saan:update --both for the public root: refuse to run as anything but the public edition on the public database
            $expect = getenv(\App\Domain\UpdateBoth::EXPECT_ENV);
            if (is_string($expect) && $expect !== '') \App\Domain\UpdateBoth::assertChildEdition($expect);
            // contract 1: the release that swapped the files starts this command of the NEW release in a fresh process
            if ($name = $this->option('finish')) {
                $r = $updater->finish((string) $name);
                $this->line(!empty($r['ok']) ? '[OK] ขั้นตอนหลังสลับไฟล์ผ่าน: '.($r['version'] ?? '') : '[ERR] '.($r['error'] ?? 'ไม่ผ่าน'));
                return !empty($r['ok']) ? self::SUCCESS : self::FAILURE;
            }
            // scheduler, every minute: settle an update whose starting process died; silent when there is nothing to do or one is still running
            // (receipts of the pre-release updater have no contract number: never touched automatically, only by an explicit --recover)
            if ($this->option('recover') && $this->option('auto')) {
                $open = array_values(array_filter(Updater::unfinished(), fn ($u) => (int) ($u['receipt']['contract'] ?? 0) >= 1));
                try { if ($open) $updater->recover($open[0]['dir']); } catch (\App\Domain\UpdateBusyException) {} catch (\Throwable $e) { $this->error('[ERR] '.$e->getMessage()); }
                try { Updater::syncHistory(); } catch (\Throwable) {}
                return self::SUCCESS;
            }
            if ($this->option('recover')) { $updater->recover($this->option('recover-dir') ?: null, (bool) $this->option('completed')); return self::SUCCESS; }
            if ($dir = $this->option('all')) { $swapping = true; return $this->end($this->all((string) $dir)); }
            $path = (string) $this->argument('package');
            if (!is_file($path)) throw new \RuntimeException('ไม่พบไฟล์แพ็กเกจ');
            if ($this->option('check')) {
                $m = $updater->inspect($path); $problems = $updater->preflight($m);
                $this->line('[OK] ลายเซ็นและไฟล์ถูกต้อง: '.$m['type'].' '.$m['id'].' '.($m['current_version'] ?? '-').' -> '.$m['version'].' ('.count($m['files']).' ไฟล์)');
                foreach ($problems as $p) $this->line('[WARN] '.$p);
                return $problems ? self::FAILURE : self::SUCCESS;
            }
            $swapping = true;
            if ($this->option('both')) { (new \App\Domain\UpdateBoth(null, fn ($line) => $this->line($line), $this->option('php') ?: null))->run($path, !$this->option('skip-db-backup')); return $this->end(self::SUCCESS); }
            $updater->apply($path, !$this->option('skip-db-backup'));
            return $this->end(self::SUCCESS);
        } catch (\Throwable $e) {
            $this->error('[ERR] '.$e->getMessage());
            return empty($swapping) ? self::FAILURE : $this->end(self::FAILURE);
        }
    }

    /**
     * `saan:update --all=<folder>`: every signed package of a release folder in the right order (core first, then modules after the modules
     * they require). Each package is applied by a PHP CLI child of its own, so it boots on the files the package before it installed; the
     * first failure stops the run (that package has rolled itself back, the ones before it stay). The database backup is taken once, by the
     * first package. With --both each child updates the internal and the public app.
     */
    private function all(string $dir): int
    {
        $files = array_merge(glob(rtrim($dir, '/\\').'/saan-core-*.zip') ?: [], glob(rtrim($dir, '/\\').'/saan-mod-*.zip') ?: []);
        if (!$files) throw new \RuntimeException('ไม่พบไฟล์ saan-core-*.zip หรือ saan-mod-*.zip ในโฟลเดอร์นี้');
        $updater = new Updater; $manifests = []; $skip = [];
        foreach ($files as $f) {
            $m = $updater->inspect($f); // signature and hashes of every package are checked before the first one is installed
            if ($m['current_version'] && Updater::compareVersions((string) $m['version'], (string) $m['current_version']) < 0) { $skip[] = basename($f).' (เก่ากว่ารุ่นที่ติดตั้ง '.$m['current_version'].')'; continue; }
            $manifests[$f] = $m;
        }
        foreach ($skip as $s) $this->line('[WARN] ข้าม '.$s);
        $order = Updater::installOrder($manifests);
        $this->line('[OK] จะติดตั้ง '.count($order).' แพ็กเกจตามลำดับ: '.implode(', ', array_map(fn ($f) => $manifests[$f]['id'].' '.$manifests[$f]['version'], $order)));
        $done = 0; $t = microtime(true);
        foreach ($order as $i => $f) {
            $args = ['saan:update', $f];
            if ($i > 0 || $this->option('skip-db-backup')) $args[] = '--skip-db-backup';
            if ($this->option('both')) $args[] = '--both';
            $r = Updater::artisanCli($args, (int) config('saan.update_finish_timeout', 1800) + 1800);
            if ($r === null) throw new \RuntimeException('เครื่องนี้เริ่ม PHP CLI ไม่ได้ (proc_open ถูกปิดหรือไม่พบ php) จึงใช้ --all ไม่ได้ ให้ติดตั้งทีละแพ็กเกจตามลำดับที่แสดงด้านบน หรือกำหนด SAAN_PHP_CLI');
            [$code, $out] = $r;
            $last = trim((string) (preg_split('/\R/', trim($out)) ?: [''])[count(preg_split('/\R/', trim($out)) ?: ['']) - 1]);
            if ($code !== 0) {
                $this->line(trim($out));
                $this->error('[ERR] หยุดที่ '.basename($f).' (ติดตั้งแล้ว '.$done.' จาก '.count($order).' แพ็กเกจ แพ็กเกจนี้คืนรุ่นเดิมแล้ว) แก้สาเหตุแล้วสั่งคำสั่งเดิมซ้ำได้');
                return self::FAILURE;
            }
            $done++; $this->line('[OK] '.($i + 1).'/'.count($order).' '.$manifests[$f]['id'].' '.$manifests[$f]['version'].($last !== '' ? ' - '.$last : ''));
        }
        $this->line('[OK] ติดตั้งครบ '.$done.' แพ็กเกจ ใช้เวลา '.round(microtime(true) - $t).' วินาที');
        return self::SUCCESS;
    }

    /**
     * After an update this process still holds classes of the release it started on while the files on disk may be another release: it ends here
     * with the exit code, so the console kernel loads nothing more (events, terminate callbacks). Tests run the command inside PHPUnit and return.
     */
    private function end(int $code): int
    {
        if (app()->runningUnitTests()) return $code;
        exit($code);
    }
}
