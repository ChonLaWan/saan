<?php
namespace App\Console\Commands;

use App\Support\Saan\Edition;
use App\Support\Saan\Publish\Publisher;
use Illuminate\Console\Command;

class SaanPublish extends Command
{
    protected $signature = 'saan:publish {--full : rebuild and resend everything (after a breach or for a new public install)} {--verify : compare per-table checksums with the public side} {--dry-run : show what would be queued, change nothing}';
    protected $description = 'Internal edition: send published content to the public edition (signed push). No-op in the combined and public editions';

    public function handle(): int
    {
        if (!Publisher::active()) { $this->line('[OK] โหมด '.Edition::current().' ไม่มีการส่งข้อมูล (ใช้ฐานข้อมูลเดียวกัน หรือเป็นฝั่งรับ)'); return self::SUCCESS; }
        $p = new Publisher();
        if ($this->option('dry-run')) {
            foreach ($this->option('full') ? $p->full(true) : $p->reconcile(true, true) as $stream => $n) $this->line("  $stream: $n");
            $this->line('[OK] dry-run: ไม่ได้เปลี่ยนอะไร');
            return self::SUCCESS;
        }
        if (Publisher::url() === '') {
            if (!$this->option('full') && !$this->option('verify')) { $this->line('[OK] ยังไม่ได้ตั้งที่อยู่หน้าบ้าน (จับคู่หรือ SAAN_PUBLIC_PUSH_URL) ข้ามการส่ง'); return self::SUCCESS; }
            $this->error('[ERR] ยังไม่ได้ตั้ง SAAN_PUBLIC_PUSH_URL'); return self::FAILURE;
        }
        if ($this->option('verify')) {
            try { $r = $p->verify(); } catch (\Throwable $e) { $this->error('[ERR] '.$e->getMessage()); return self::FAILURE; }
            foreach ($r['streams'] as $s => $v) $this->line(sprintf('  %-22s %s  local=%d remote=%s', $s, $v['match'] ? '[OK]' : '[ERR]', $v['local']['count'], $v['remote']['count'] ?? '-'));
            if ($r['pending'] > 0) $this->warn('[WARN] ยังมี '.$r['pending'].' ชุดค้างส่ง ผลอาจต่างชั่วคราว');
            $this->line($r['ok'] ? '[OK] ตรงกันทุกตาราง' : '[ERR] มีตารางที่ไม่ตรงกัน: รัน saan:publish --full');
            return $r['ok'] ? self::SUCCESS : self::FAILURE;
        }
        if ($this->option('full')) {
            try { $n = array_sum($p->full()); } catch (\Throwable $e) { $this->error('[ERR] '.$e->getMessage()); return self::FAILURE; }
            $this->line('[OK] จัดคิวส่งทั้งชุดใหม่ '.$n.' แถว');
        }
        $r = $p->run();
        $this->line('[OK] ส่งแล้ว '.($r['sent'] ?? 0).' ชุด'.(($r['failed'] ?? 0) ? ' | [WARN] ล้มเหลว: '.($r['error'] ?? '') : '').(isset($r['note']) ? ' | '.$r['note'] : ''));
        return ($r['failed'] ?? 0) ? self::FAILURE : self::SUCCESS;
    }
}
