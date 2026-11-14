<?php
namespace App\Console\Commands;

use App\Http\Controllers\SaanSetupController;
use App\Support\Saan\Edition;
use Illuminate\Console\Command;

class SaanPairToken extends Command
{
    protected $signature = 'saan:pair-token {--remove : delete the token file instead}';
    protected $description = 'Public edition: write storage/app/private/saan-pair.token (random, 40 characters) that opens the one-time pairing page /_saan/setup; the page closes itself after a pairing or after 10 wrong tokens';

    public function handle(): int
    {
        if (!Edition::isPublic()) { $this->error('[ERR] ใช้ได้เฉพาะหน้าบ้าน (SAAN_EDITION=public)'); return self::FAILURE; }
        $f = SaanSetupController::tokenFile();
        if ($this->option('remove')) { @unlink($f); $this->line('[OK] ลบไฟล์รหัสเจ้าของเครื่องแล้ว'); return self::SUCCESS; }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        do {
            $t = '';
            for ($i = 0; $i < 40; $i++) $t .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        } while (!SaanSetupController::strongToken($t));
        if (!is_dir(dirname($f))) @mkdir(dirname($f), 0700, true);
        $old = umask(0077);
        try { $ok = file_put_contents($f, $t."\n", LOCK_EX) !== false; } finally { umask($old); }
        if (!$ok) { $this->error('[ERR] เขียนไฟล์ไม่ได้: '.$f); return self::FAILURE; }
        @chmod($f, 0600);
        $this->line('[OK] สร้างไฟล์รหัสเจ้าของเครื่อง: '.$f);
        $this->line('     รหัส: '.$t);
        $this->warn('[WARN] ไฟล์จะถูกลบเมื่อจับคู่สำเร็จ หรือผิด 10 ครั้ง ถ้าไม่ใช้แล้วลบเองด้วย saan:pair-token --remove');
        return self::SUCCESS;
    }
}
