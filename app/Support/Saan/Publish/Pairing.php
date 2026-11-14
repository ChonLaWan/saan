<?php

namespace App\Support\Saan\Publish;

use App\Support\Saan\Edition;
use App\Support\Saan\NetKey;
use App\Support\Saan\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * S6 pairing. The internal edition shows a one-time code (160 bits, valid 15 minutes, single use); the public edition presents it over HTTPS to
 * POST /saan-pair/redeem together with its own push URL and receives the pairing bundle: the public halves of the internal keys (push, pull, inbox)
 * and the unlock key. No secret key of the internal side ever leaves it. The internal side stores the public push URL, so it needs no .env edit.
 * The code is stored only as a SHA-256, redeemed atomically, rate limited per network (/64 and /48, never in total: 160 bits need no global ceiling, and one would be a lever for anybody to lock pairing), and every failure looks the same.
 */
final class Pairing
{
    public const TTL = 900;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * The code is bound to the public push URL here (default: the saved one): redeeming it for any other URL is refused, so a leaked code
     * cannot move the push and pull target of the internal edition.
     * @return array{code:string,expires_at:string} the code is shown once and cannot be read back
     */
    public static function issue(?int $userId = null, ?int $now = null, ?string $pushUrl = null): array
    {
        $now ??= time();
        $bound = Publisher::acceptable(trim((string) ($pushUrl ?? Publisher::url())));
        if ($bound === '' || !preg_match('~/_saan/push$~D', $bound)) throw new RuntimeException('ยังไม่ได้ตั้งที่อยู่หน้าบ้าน (https://.../_saan/push) จึงออกรหัสจับคู่ไม่ได้');
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        $code = '';
        foreach (str_split($bits, 5) as $chunk) $code .= self::ALPHABET[bindec($chunk)];
        $exp = date('Y-m-d H:i:s', $now + self::TTL);
        DB::transaction(function () use ($code, $exp, $userId, $now, $bound) {
            DB::table('saan_pairing_codes')->whereNull('used_at')->where('expires_at', '>', date('Y-m-d H:i:s', $now))->update(['used_at' => date('Y-m-d H:i:s', $now)]); // a new code replaces an unused one
            DB::table('saan_pairing_codes')->where('expires_at', '<', date('Y-m-d H:i:s', $now - 86400))->delete();
            DB::table('saan_pairing_codes')->insert(['code_hash' => hash('sha256', $code), 'expires_at' => $exp, 'push_url' => $bound, 'created_by' => $userId, 'created_at' => date('Y-m-d H:i:s', $now)]);
        });
        return ['code' => implode('-', str_split($code, 4)), 'expires_at' => $exp];
    }

    /** Internal edition: remembers the public push URL, resets the inbox counters and returns the pairing bundle (public halves of the keys only). */
    private static function register(string $url, int $now): array
    {
        Settings::put('saan.public_push_url', $url);
        foreach (['inbox.cursor', 'inbox.retry'] as $k) \App\Support\Saan\Inbox\State::mark(null, $k, $now, 0, 0); // a freshly paired public side may number its envelopes from 1 again
        Settings::put('saan.paired_at', date('Y-m-d H:i:s', $now));
        return Keys::bundle();
    }

    /**
     * S7 standard installer: both apps live on this machine, so no code and no HTTP round trip are needed. The internal half is the same as redeem()
     * (register), the public half is applyBundle() into the public app's own key directory. Returns the same facts as pairWith().
     * @return array{push_url:string,inbox_kid:string}
     */
    public static function pairLocal(string $publicKeysDir, string $pushUrl, ?int $now = null): array
    {
        if (!Edition::isInternal()) throw new RuntimeException('จับคู่ในเครื่องเดียวทำได้จากระบบภายในเท่านั้น');
        if (Publisher::acceptable($pushUrl) === '' || !preg_match('~/_saan/push$~D', $pushUrl)) throw new RuntimeException('ที่อยู่รับข้อมูลของหน้าบ้านต้องเป็น https://.../_saan/push');
        $bundle = self::register($pushUrl, $now ?? time());
        Keys::applyBundle($bundle, $publicKeysDir);
        return ['push_url' => $pushUrl, 'inbox_kid' => (string) $bundle['inbox_kid']];
    }

    /** Uppercase base32 without separators, or null when it cannot be a code. */
    public static function normalize(string $code): ?string
    {
        $c = strtoupper(preg_replace('/[\s-]+/', '', $code) ?? '');
        return preg_match('/^[A-Z2-7]{32}$/D', $c) ? $c : null;
    }

    /** @return array{0:int,1:array} HTTP status and reply (the bundle on success) */
    public static function redeem(string $code, string $pushUrl, ?string $ip, ?int $now = null): array
    {
        if (!Edition::isInternal()) return [404, ['error' => 'not_found']];
        $now ??= time();
        $net = 'saan-pair:'.NetKey::rate($ip);
        $wide = 'saan-pair-wide:'.NetKey::wide($ip);
        if (RateLimiter::tooManyAttempts($net, 5) || RateLimiter::tooManyAttempts($wide, 20)) return [429, ['error' => 'rate_limited']];
        RateLimiter::hit($net, 900);
        RateLimiter::hit($wide, 900);
        $c = self::normalize($code);
        $url = rtrim(Publisher::acceptable(trim($pushUrl)), '/');
        $fail = [403, ['error' => 'invalid_code']];
        if ($c === null) return $fail; // junk does not count against the shared counter (it could lock pairing for everybody)
        $hash = hash('sha256', $c);
        $stamp = date('Y-m-d H:i:s', $now);
        $row = DB::table('saan_pairing_codes')->where('code_hash', $hash)->whereNull('used_at')->where('expires_at', '>', $stamp)->first();
        // the code only works for the push URL it was issued for: the caller cannot choose where the internal side will push to and pull from
        if (!$row || $row->push_url === null || $url === '' || !hash_equals(rtrim((string) $row->push_url, '/'), $url)) {
            if (!$row) { try { \App\Support\Saan\Inbox\State::mark(null, 'pair.guess', $now, 1); } catch (\Throwable) {} } // a well formed code that matches nothing: guessing (a doctor signal only, it blocks nobody)
            return $fail;
        }
        // one atomic statement: of two simultaneous requests with the same code exactly one gets a row
        $used = DB::table('saan_pairing_codes')->where('id', $row->id)->whereNull('used_at')->update(['used_at' => $stamp]);
        if ($used !== 1) return $fail;
        RateLimiter::clear($net);
        $bundle = self::register((string) $row->push_url, $now);
        try { (new Publisher())->full(); } catch (\Throwable) { /* queued later by the hourly reconcile or the admin button */ }
        return [200, ['ok' => true, 'bundle' => $bundle]];
    }

    /**
     * Public edition: redeems $code at the internal edition and stores the bundle. $transport(string $url, array $body): array{0:int,1:array} is for tests.
     * @return array{push_url:string,inbox_kid:string}
     */
    public static function pairWith(string $internalUrl, string $code, string $pushUrl, ?callable $transport = null): array
    {
        if (!Edition::isPublic()) throw new RuntimeException('คำสั่งนี้ใช้ได้เฉพาะหน้าบ้าน (SAAN_EDITION=public)');
        $base = rtrim(trim($internalUrl), '/');
        $p = parse_url($base);
        $host = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        if (!($scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true))) || $host === '' || isset($p['user']) || isset($p['query']) || isset($p['fragment'])) {
            throw new RuntimeException('ที่อยู่ระบบภายในต้องขึ้นต้นด้วย https:// (http ใช้ได้เฉพาะ localhost)');
        }
        $n = Pairing::normalize($code);
        if ($n === null) throw new RuntimeException('รหัสจับคู่ไม่ถูกต้อง');
        if (Publisher::acceptable($pushUrl) === '') throw new RuntimeException('ที่อยู่รับข้อมูลของหน้าบ้านต้องเป็น https://');
        $url = $base.'/saan-pair/redeem';
        $body = ['code' => $n, 'push_url' => $pushUrl];
        if ($transport) [$st, $reply] = $transport($url, $body);
        else {
            // never follow a redirect: the code must not travel to any other address
            $r = Http::withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 10])->acceptJson()->connectTimeout(5)->timeout(30)->post($url, $body);
            [$st, $reply] = [$r->status(), Publisher::decodeReply($r)];
        }
        if ($st === 429) throw new RuntimeException('ลองบ่อยเกินไป รอ 15 นาทีแล้วขอรหัสใหม่');
        if ($st === 403) throw new RuntimeException('รหัสจับคู่ไม่ถูกต้อง หมดอายุ หรือถูกใช้ไปแล้ว');
        if ($st !== 200 || !is_array($reply['bundle'] ?? null)) throw new RuntimeException('ระบบภายในตอบ '.$st.' '.Wire::code($reply['error'] ?? ''));
        Keys::applyBundle($reply['bundle']);
        return ['push_url' => $pushUrl, 'inbox_kid' => Keys::keyId((string) Keys::peer('inbox.pub'))];
    }

    /** The push URL a public install registers: its own APP_URL plus /_saan/push. */
    public static function ownPushUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/_saan/push';
    }
}
