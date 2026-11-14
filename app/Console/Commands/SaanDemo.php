<?php
namespace App\Console\Commands;

use App\Domain\Demo\DemoSchool;
use App\Models\User;
use App\Support\Saan\Edition;
use Illuminate\Console\Command;

/**
 * Full-system trial data for an install that was made with the blank starter and is still empty:
 *   php artisan saan:demo install   create the fictional school and the trial accounts (passwords are printed once)
 *   php artisan saan:demo status    what is there
 * There is no uninstall on purpose: a trial is thrown away (new folder, new database) before the real school starts.
 */
class SaanDemo extends Command
{
    protected $signature = 'saan:demo {action : install | status} {--url= : address the account list should point to (default APP_URL)}';
    protected $description = 'Trial school for testing every module: fictional people and trial accounts (blank trial installs only, never a real school)';

    public function handle(DemoSchool $demo): int
    {
        $action = (string) $this->argument('action');
        if ($action === 'status') return $this->status($demo);
        if ($action !== 'install') { $this->error('[ERR] ใช้ได้เฉพาะ install หรือ status'); return self::INVALID; }

        if (Edition::isPublic()) { $this->error('[ERR] ติดตั้งข้อมูลทดลองบนเว็บหน้าบ้าน (public edition) ไม่ได้ ให้รันที่ระบบภายใน'); return self::FAILURE; }
        if ($why = $demo->refusal()) { $this->error('[ERR] '.$why); return self::FAILURE; }
        $admin = User::where('school_id', (int) config('school.school_id'))->where('role', 'admin')->where('is_active', true)->where('is_demo', false)->orderBy('id')->first();
        if (!$admin) { $this->error('[ERR] ยังไม่มีบัญชีผู้ดูแลระบบ ให้ติดตั้งระบบให้เสร็จก่อน'); return self::FAILURE; }
        $url = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $this->line('[WARN] ข้อมูลทดลองทั้งหมดเป็นข้อมูลสมมติ ใช้ทดลองเท่านั้น ห้ามใช้ระบบนี้กับโรงเรียนจริง และไม่มีคำสั่งถอนออก (ทิ้งระบบทดลองแล้วติดตั้งใหม่เมื่อจะใช้จริง)');
        try {
            $r = $demo->install((int) $admin->id, $url, 'command');
        } catch (\Throwable $e) {
            report($e);
            $this->error('[ERR] สร้างข้อมูลทดลองไม่สำเร็จ ไม่มีข้อมูลค้างในระบบ: '.mb_substr($e->getMessage(), 0, 300));
            return self::FAILURE;
        }
        $this->line('[OK] สร้างโรงเรียนสมมติแล้วใน '.$r['seconds'].' วินาที · โมดูลที่มีข้อมูล '.count($r['filled']).' โมดูล');
        foreach ($r['skipped'] as $id => $reason) $this->line('[WARN] ข้ามโมดูล '.$id.': '.$reason);
        $this->line('');
        $this->line('บัญชีทดลอง (แสดงครั้งเดียว รหัสผ่านสุ่มใหม่ทุกครั้ง บัญชีผู้ดูแลระบบคือบัญชีเดิมของท่าน)');
        $this->table(['บทบาท', 'ชื่อเข้าระบบ', 'รหัสผ่าน', 'เริ่มที่'], array_map(fn ($a) => [$a['role'], $a['login'], $a['password'], $a['url']], $r['accounts']));
        $this->line('[OK] สำเนารายการอยู่ที่ '.$r['file'].' (สิทธิ์ 0600) ลบไฟล์นี้เมื่อจดแล้ว');
        return self::SUCCESS;
    }

    private function status(DemoSchool $demo): int
    {
        $s = $demo->status();
        if (!$s['installed']) {
            $this->line('[OK] ระบบนี้ไม่มีโรงเรียนสมมติของชุดทดลอง');
            $this->line($s['refusal'] ? '[WARN] ติดตั้งชุดทดลองไม่ได้: '.$s['refusal'] : '[OK] ติดตั้งได้ด้วย php artisan saan:demo install (สำหรับระบบทดลองเท่านั้น)');
            return self::SUCCESS;
        }
        $this->line('[WARN] ระบบนี้เป็นระบบทดลอง: มีโรงเรียนสมมติและบัญชีทดลอง ห้ามใช้กับโรงเรียนจริง');
        $this->line('ติดตั้งเมื่อ '.$s['installed_at'].' ('.$s['source'].') รุ่น '.$s['version'].' ใช้เวลา '.$s['seconds'].' วินาที');
        $this->line('นักเรียน '.$s['students'].' คน · บุคลากร '.$s['teachers'].' คน · ห้องเรียน '.$s['classrooms'].' ห้อง · บัญชีผู้ใช้ '.$s['users'].' บัญชี');
        foreach ($s['skipped'] as $id => $reason) $this->line('[WARN] โมดูลที่ไม่ได้ลงข้อมูล '.$id.': '.$reason);
        $this->line($s['account_file'] ? '[WARN] ไฟล์รายการบัญชีทดลองยังอยู่ที่ '.$s['account_file'].' ลบเมื่อจดแล้ว' : '[OK] ไฟล์รายการบัญชีทดลองถูกลบแล้ว (รหัสผ่านไม่ได้เก็บไว้ที่อื่น ผู้ดูแลตั้งรหัสใหม่ให้บัญชีได้ที่หน้าจัดการผู้ใช้งาน)');
        return self::SUCCESS;
    }
}
