<?php
namespace App\Console\Commands;

use App\Domain\SelfUpdate;
use App\Domain\SelfUpdateException;
use Illuminate\Console\Command;

class SaanSelfUpdate extends Command
{
    protected $signature = 'saan:self-update {--check : Fetch the signed feed now (only when the feed is switched on)} {--plan : Print what would be installed (default)} {--download : Download the planned packages into storage/app/private/updates/incoming} {--install : Download and install them (saan:update --all in a PHP CLI child)} {--auto : Scheduler tick: install a PATCH release by itself inside the night window (mode auto_patch only)} {--yes : Do not ask before --install}';
    protected $description = 'Self-update: check the signed feed, show the plan, download and install packages (the same as the back office page /admin/updates)';

    public function handle(): int
    {
        $su = app(SelfUpdate::class);
        try {
            if ($this->option('auto')) {
                $r = $su->runAuto();
                if ($r['action'] !== 'none') $this->line('['.($r['action'] === 'failed' || $r['action'] === 'blocked' ? 'WARN' : 'OK').'] '.$r['action'].': '.$r['message']);
                return $this->end($r['action'] === 'failed' ? self::FAILURE : self::SUCCESS, $r['action'] === 'installed' || $r['action'] === 'failed');
            }
            if ($this->option('check')) {
                if (!\App\Support\Modules\UpdateFeed::enabled()) { $this->line('[WARN] ปิดการตรวจรุ่นใหม่อยู่ ไม่ได้เชื่อมต่อภายนอก (เปิดที่ /admin/updates)'); return self::SUCCESS; }
                $last = $su->check();
                if (!($last['ok'] ?? false)) { $this->error('[ERR] '.($last['error'] ?? 'ตรวจไม่สำเร็จ')); return self::FAILURE; }
                $this->line('[OK] ตรวจรุ่นใหม่แล้ว');
            }
            $plan = $su->plan(true);
            if (!$this->option('install') && !$this->option('download')) { $this->printPlan($plan); return self::SUCCESS; }
            $this->printPlan($plan);
            if (!$plan['any']) return self::SUCCESS;
            foreach ($plan['blockers'] as $b) if ($b['download']) { $this->error('[ERR] '.$b['message']); return self::FAILURE; }
            if (!$plan['downloadable']) { $this->error('[ERR] ฟีดนี้ไม่มีข้อมูลดาวน์โหลด ใช้ php artisan saan:update <ไฟล์> กับไฟล์ที่อัปโหลดเอง'); return self::FAILURE; }
            if ($this->option('install') && !$this->option('yes') && (!$this->input->isInteractive() || !$this->confirm('ติดตั้ง '.count($plan['packages']).' แพ็กเกจนี้ตอนนี้? ระหว่างติดตั้งเว็บไซต์จะปิดชั่วคราว'))) {
                $this->error('[ERR] ไม่ได้ยืนยัน ไม่ติดตั้ง (ใส่ --yes เมื่อสั่งแบบไม่โต้ตอบ)');
                return self::FAILURE;
            }
            foreach ($plan['packages'] as $p) {
                $this->line('[..] ดาวน์โหลด '.$p['zip'].' ('.$this->mb($p['bytes']).')');
                do { $r = $su->download($p['key']); if ($r['status'] === 'partial') $this->line('     '.$this->mb($r['received']).' / '.$this->mb($r['bytes'])); } while ($r['status'] === 'partial');
                $this->line('[OK] '.$p['zip'].($r['skipped'] ? ' (มีอยู่แล้วและตรงกับฟีด)' : ' ดาวน์โหลดแล้ว ตรวจ SHA-256 และลายเซ็นผ่าน'));
            }
            if (!$this->option('install')) return self::SUCCESS;
            $entries = $su->plan(true)['packages'];
            $r = $su->install($entries);
            if ($r['output'] !== '') $this->line($r['output']);
            // the files on disk are the new release now: end here without loading more classes of this process
            if (!$r['ok']) { $this->error('[ERR] ติดตั้งไม่สำเร็จ ดูข้อความด้านบน'); return $this->end(self::FAILURE, true); }
            return $this->end(self::SUCCESS, true);
        } catch (SelfUpdateException $e) {
            $this->error('[ERR] '.$e->getMessage());
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('[ERR] '.$e->getMessage());
            return self::FAILURE;
        }
    }

    private function mb(?int $b): string
    {
        return $b === null ? '-' : number_format($b / 1048576, 1).' MB';
    }

    private function printPlan(array $plan): void
    {
        $this->line('[OK] รุ่นที่ติดตั้ง: สาน '.config('saan.version').' · Laravel '.SelfUpdate::laravelVersion().' · PHP '.PHP_VERSION);
        $this->line('[OK] โหมด: '.SelfUpdate::mode());
        if (!$plan['any']) $this->line('[OK] ไม่มีรุ่นใหม่ที่ต้องติดตั้ง');
        foreach ($plan['packages'] as $i => $p) $this->line('     '.($i + 1).'. '.$p['name'].' '.($p['from'] ?? '-').' -> '.$p['to'].($p['same_minor'] ? ' (แพตช์)' : '').($p['zip'] ? ' · '.$this->mb($p['bytes']).($p['downloaded'] ? ' · ดาวน์โหลดแล้ว' : '') : ' · ไม่มีข้อมูลดาวน์โหลด (ติดตั้งจากไฟล์ที่อัปโหลดเอง)'));
        if ($plan['laravel']['changes']) $this->line('     Laravel '.$plan['laravel']['from'].' -> '.$plan['laravel']['to']);
        foreach ($plan['blockers'] as $b) $this->line('[WARN] '.$b['message']);
    }

    /** After an install this process may hold classes of the release it started on: it ends with the exit code (tests return). */
    private function end(int $code, bool $swapped): int
    {
        if (!$swapped || app()->runningUnitTests()) return $code;
        exit($code);
    }
}
