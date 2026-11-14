<?php
namespace App\Console\Commands;

use App\Support\Saan\Publish\Pairing;
use Illuminate\Console\Command;

class SaanPair extends Command
{
    protected $signature = 'saan:pair {internal_url : https://app.school.ac.th} {code : one-time pairing code from the internal admin page} {--push-url= : this site\'s push endpoint (default APP_URL + /_saan/push)}';
    protected $description = 'Public edition: pair with the internal edition using a one-time code (stores the internal public keys and the unlock key)';

    public function handle(): int
    {
        try {
            $r = Pairing::pairWith((string) $this->argument('internal_url'), (string) $this->argument('code'), (string) ($this->option('push-url') ?: Pairing::ownPushUrl()));
        } catch (\Throwable $e) {
            $this->error('[ERR] '.$e->getMessage());
            return self::FAILURE;
        }
        $this->line('[OK] จับคู่แล้ว ที่อยู่รับข้อมูลที่ลงทะเบียน: '.$r['push_url'].' (key id ของ inbox: '.$r['inbox_kid'].')');
        $this->line('[OK] ระบบภายในจะส่งข้อมูลหน้าเว็บชุดเต็ม (saan:publish --full) ให้เองภายใน 1-2 นาที');
        return self::SUCCESS;
    }
}
