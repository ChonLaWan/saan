<?php

namespace Saan\Modules\WebIta\Console;

use App\Support\Fiscal;
use Illuminate\Console\Command;
use Saan\Modules\WebIta\ItaCheck;

/**
 * saan:ita-check [--year=2570] [--json]
 * Prints the same completeness report as the admin page and exits 1 when any O item is missing or has a broken link, or the year is not published (for monitoring / cron).
 * Makes no network call: internal links are resolved against this installation, https links are only checked for syntax.
 */
class ItaCheckCommand extends Command
{
    protected $signature = 'saan:ita-check {--year= : ปีงบประมาณ พ.ศ. (ค่าเริ่มต้น: ปีงบประมาณปัจจุบัน)} {--json : พิมพ์ผลเป็น JSON}';
    protected $description = 'Check that every ITA O1..O18 item of a fiscal year has content (exit 1 when something is missing or broken)';

    public function handle(): int
    {
        $y = $this->option('year');
        if ($y !== null && (!ctype_digit((string) $y) || (int) $y < 2560 || (int) $y > 2700)) { $this->error('[ERR] --year ต้องเป็นปีงบประมาณ พ.ศ. 2560-2700'); return 2; }
        $fy = $y !== null ? (int) $y : Fiscal::current();
        $r = ItaCheck::run($fy);
        if ($this->option('json')) {
            $this->line(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return $r['ok'] ? self::SUCCESS : self::FAILURE;
        }
        $this->line('ITA ปีงบประมาณ '.$fy.($r['year_id'] ? ' ('.($r['year_status'] === 'published' ? 'เผยแพร่แล้ว' : 'ฉบับร่าง').')' : ' (ยังไม่สร้างข้อมูลปีนี้)'));
        $tag = ['complete' => '[OK]  ', 'auto-ok' => '[OK]  ', 'missing' => '[WARN]', 'link-broken' => '[ERR] '];
        foreach ($r['items'] as $i) $this->line($tag[$i['status']].' '.str_pad($i['code'], 4).' '.str_pad($i['status'], 11).' '.$i['title'].' - '.$i['reason'].($i['status'] === 'complete' || $i['status'] === 'auto-ok' ? '' : '  แก้ที่: '.$i['fix_path']));
        if ($r['year_id'] && !$r['published']) $this->line('[WARN] ปีนี้ยังเป็นฉบับร่าง ผู้ชมยังไม่เห็นและระบบยังไม่ส่งข้อมูลไปเว็บสาธารณะ จึงถือว่ายังไม่ผ่าน (exit 1)');
        $s = $r['summary'];
        $this->line('สรุป: ครบ '.$s['complete'].', ลิงก์อัตโนมัติ '.$s['auto_ok'].', ขาด '.$s['missing'].', ลิงก์เสีย '.$s['link_broken'].' จากทั้งหมด '.$s['total'].' ข้อ');
        return $r['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
