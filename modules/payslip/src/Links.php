<?php

namespace Saan\Modules\Payslip;

use App\Support\Saan\Settings;

/**
 * "บริการบุคลากร": links to the central pension service and the central civil-servant salary service. Pensioners are paid centrally and have no account here.
 * Label and address come from settings only (no system name is hard-coded: the central systems may be renamed or moved). Only https URLs are ever rendered.
 */
final class Links
{
    public const SLOTS = [
        'pension' => 'บริการบำนาญ (ระบบส่วนกลาง)',
        'salary' => 'บริการเงินเดือนข้าราชการ (ระบบส่วนกลาง)',
    ];

    /** A clean absolute https URL or null: no credentials, no control characters, no markup, no javascript:/data:/http:. */
    public static function safeUrl(mixed $v): ?string
    {
        $u = trim((string) $v);
        if ($u === '' || strlen($u) > 400 || preg_match('/[\x00-\x20\x7F"\'<>\\\\]/', $u)) return null;
        if (!preg_match('#^https://([A-Za-z0-9-]+\.)+[A-Za-z0-9-]{2,}(:\d{1,5})?([/?\#][^\s]*)?$#D', $u)) return null;
        $parts = parse_url($u);
        return $parts !== false && ($parts['scheme'] ?? '') === 'https' && empty($parts['user']) && empty($parts['pass']) ? $u : null;
    }

    /** @return list<array{key:string,label:string,url:string}> */
    public static function all(): array
    {
        $out = [];
        foreach (self::SLOTS as $slot => $default) {
            $url = self::safeUrl(Settings::get('payslip.link_'.$slot.'_url', ''));
            if ($url === null) continue;
            $label = trim((string) Settings::get('payslip.link_'.$slot.'_label', ''));
            $out[] = ['key' => $slot, 'label' => $label !== '' && mb_strlen($label) <= 80 ? $label : $default, 'url' => $url];
        }
        return $out;
    }
}
