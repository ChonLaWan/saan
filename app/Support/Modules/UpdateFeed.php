<?php

namespace App\Support\Modules;

use App\Domain\Updater;
use App\Support\Saan\Settings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Optional "new version available" feed. Off by default (Settings updates.feed_enabled). One GET of feed.json and one of
 * feed.sig, no identifiers sent, nothing installed. The feed is trusted only when its Ed25519 signature verifies with
 * the same public key that signs packages (config saan.release_public_key).
 *
 * feed.json: {"core":{"version","released","summary","notes_url"},"modules":{"<id>":{"version","summary","notes_url"}}}
 */
class UpdateFeed
{
    public const MAX_BYTES = 262144;

    public static function enabled(): bool
    {
        return Settings::get('updates.feed_enabled', false) === true;
    }

        /** The stored record is re-compared with what is installed now, so a notice disappears after the update and when the feed is switched off. */
    public static function status(): array
    {
        $enabled = self::enabled();
        $stored = Settings::get('updates.available');
        $available = null;
        if ($enabled && is_array($stored)) {
            $current = (string) config('saan.version');
            $core = is_array($stored['core'] ?? null) && is_string($stored['core']['version'] ?? null) && Updater::compareVersions($stored['core']['version'], $current) > 0 ? $stored['core'] : null;
            $registry = app(ModuleRegistry::class);
            $modules = [];
            foreach (is_array($stored['modules'] ?? null) ? $stored['modules'] : [] as $mu) {
                $local = is_array($mu) && is_string($mu['id'] ?? null) && is_string($mu['version'] ?? null) ? $registry->get($mu['id']) : null;
                if ($local && Updater::compareVersions($mu['version'], $local['version']) > 0) $modules[] = ['current' => $local['version']] + $mu;
            }
            $available = ['checked_at' => $stored['checked_at'] ?? null, 'current' => $current, 'core' => $core, 'modules' => $modules, 'any' => $core !== null || $modules !== []];
        }
        return ['enabled' => $enabled, 'feed' => (string) config('saan.update_feed'), 'available' => $available, 'last_check' => Settings::get('updates.last_check')];
    }
    /** Fetch, verify and store. Returns the stored "available" record. Never throws on network or signature failure: it records the error. */
    public function check(): array
    {
        $now = now()->toIso8601String();
        try {
            $record = $this->fetch();
            Settings::put('updates.available', $record);
            Settings::put('updates.last_check', ['at' => $now, 'ok' => true, 'error' => null]);
            return $record;
        } catch (\Throwable $e) {
            Settings::put('updates.last_check', ['at' => $now, 'ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            return (array) (Settings::get('updates.available') ?? []);
        }
    }

    private function fetch(): array
    {
        $url = (string) config('saan.update_feed');
        if (!preg_match('~^https://[^\s/]+/.*feed\.json$~D', $url)) throw new RuntimeException('ที่อยู่ฟีดต้องเป็น https และลงท้ายด้วย feed.json');
        $sigUrl = substr($url, 0, -strlen('feed.json')).'feed.sig';
        $json = $this->get($url); $sig = $this->get($sigUrl);
        $public = base64_decode((string) config('saan.release_public_key'), true);
        $raw = base64_decode(trim($sig), true);
        if (!$public || !$raw || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || !sodium_crypto_sign_verify_detached($raw, $json, $public)) throw new RuntimeException('ลายเซ็นฟีดไม่ถูกต้อง จึงไม่ใช้ข้อมูลนี้');
        $feed = json_decode($json, true, 16);
        if (!is_array($feed)) throw new RuntimeException('ฟีดอ่านไม่ได้');
        // generated_at is inside the signed body: an older signed feed cannot replace a newer accepted one (replay)
        $gen = is_string($feed['generated_at'] ?? null) ? strtotime($feed['generated_at']) : false;
        $prev = (int) Settings::get('updates.feed_generated_at', 0);
        if ($gen !== false && $gen < $prev) throw new RuntimeException('ฟีดเก่ากว่าที่เคยรับไว้ จึงไม่ใช้ข้อมูลนี้');
        if ($gen !== false) Settings::put('updates.feed_generated_at', $gen);

        $current = (string) config('saan.version');
        $core = self::release($feed['core'] ?? null);
        $registry = app(ModuleRegistry::class);
        $modules = [];
        foreach (is_array($feed['modules'] ?? null) ? $feed['modules'] : [] as $id => $info) {
            $rel = self::release($info); $local = is_string($id) ? $registry->get($id) : null;
            if ($rel && $local && Updater::compareVersions($rel['version'], $local['version']) > 0) $modules[] = ['id' => $id, 'name' => $local['name'], 'current' => $local['version']] + $rel;
        }
        $newCore = $core && Updater::compareVersions($core['version'], $current) > 0 ? $core : null;
        return ['checked_at' => now()->toIso8601String(), 'current' => $current, 'core' => $newCore, 'modules' => $modules, 'any' => $newCore !== null || $modules !== []];
    }

        private function get(string $url): string
    {
        $res = Http::timeout(10)->connectTimeout(10)->withHeaders(['Accept' => '*/*'])
            ->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https']], 'stream' => true])->get($url);
        if (!$res->successful()) throw new RuntimeException('ดึงฟีดไม่สำเร็จ (HTTP '.$res->status().')');
        $len = $res->header('Content-Length');
        if ($len !== '' && (int) $len > self::MAX_BYTES) throw new RuntimeException('ขนาดฟีดไม่ถูกต้อง');
        $stream = $res->toPsrResponse()->getBody(); $body = '';
        while (!$stream->eof()) {
            $body .= $stream->read(8192);
            if (strlen($body) > self::MAX_BYTES) throw new RuntimeException('ขนาดฟีดไม่ถูกต้อง');
        }
        if ($body === '') throw new RuntimeException('ขนาดฟีดไม่ถูกต้อง');
        return $body;
    }
    /** Only well-formed values are kept; links must be https. */
    private static function release(mixed $r): ?array
    {
        if (!is_array($r) || !is_string($r['version'] ?? null) || !preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/D', $r['version'])) return null;
        $notes = is_string($r['notes_url'] ?? null) && preg_match('~^https://[^\s<>"\']{1,300}$~D', $r['notes_url']) ? $r['notes_url'] : null;
        $date = is_string($r['released'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $r['released']) ? $r['released'] : null;
        return ['version' => $r['version'], 'released' => $date, 'summary' => is_string($r['summary'] ?? null) ? mb_substr(strip_tags($r['summary']), 0, 300) : '', 'notes_url' => $notes];
    }
}
