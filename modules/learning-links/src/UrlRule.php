<?php

namespace Saan\Modules\LearningLinks;

use Illuminate\Validation\ValidationException;

/** Strict https-only URL check for learning resources. Anything else (javascript:, data:, http:, user:pass@, private hosts) is a 422. */
final class UrlRule
{
    /** @return string the normalised URL (scheme and host lower-cased) */
    public static function check(string $raw, string $field = 'url'): string
    {
        $url = trim($raw);
        $bad = fn (string $m): never => throw ValidationException::withMessages([$field => [$m]]);
        if ($url === '') $bad('กรุณากรอกลิงก์');
        if (strlen($url) > 500) $bad('ลิงก์ยาวเกิน 500 ตัวอักษร');
        // whitespace, control and format characters (incl. zero-width and bidi) plus characters that break out of attributes
        if (preg_match('/[\p{C}\p{Z}<>"\'`\\\\]/u', $url) === 1) $bad('ลิงก์มีอักขระที่ไม่อนุญาต');
        if (!preg_match('~^https://~i', $url)) $bad('ลิงก์ต้องขึ้นต้นด้วย https:// เท่านั้น');
        $p = parse_url($url);
        if ($p === false || empty($p['host'])) $bad('ลิงก์ไม่ถูกต้อง');
        if (isset($p['user']) || isset($p['pass'])) $bad('ลิงก์ต้องไม่มีชื่อผู้ใช้หรือรหัสผ่านแทรกอยู่');
        if (isset($p['port']) && (int) $p['port'] !== 443) $bad('ลิงก์ต้องใช้พอร์ตมาตรฐานของ https');
        $host = strtolower(rtrim($p['host'], '.'));
        if (str_starts_with($host, '[')) $bad('ไม่รองรับที่อยู่ IPv6 ตรง ๆ กรุณาใช้ชื่อโดเมน');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) $bad('ลิงก์ชี้ไปยังเครือข่ายภายใน ไม่อนุญาต');
        } else {
            if (!str_contains($host, '.') || preg_match('/\.(local|localhost|internal|lan|home|test|invalid)$/', $host) || $host === 'localhost') $bad('ชื่อโดเมนของลิงก์ไม่ถูกต้องหรือเป็นเครือข่ายภายใน');
            // browsers read 127.1, 0x7f.0.0.1 or 0177.0.0.1 as IPv4: a numeric or hex last label is never a real public name
            $last = substr(strrchr('.'.$host, '.'), 1);
            if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/i', $last)) $bad('ลิงก์ชี้ไปยังเครือข่ายภายใน ไม่อนุญาต');
            foreach (explode('.', $host) as $label) {
                if ($label === '' || mb_strlen($label) > 63 || !preg_match('/^[\p{L}\p{N}]([\p{L}\p{N}-]*[\p{L}\p{N}])?$/u', $label)) $bad('ชื่อโดเมนของลิงก์ไม่ถูกต้อง');
            }
        }
        $rest = substr($url, strlen('https://') + strlen($p['host']));
        return 'https://'.$host.$rest;
    }

    public static function hash(string $normalised): string
    {
        return sha1(rtrim($normalised, '/'));
    }
}
