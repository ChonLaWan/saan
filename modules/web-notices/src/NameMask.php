<?php

namespace Saan\Modules\WebNotices;

/** Server-side name masking. Masked output is the only thing the public view ever receives. */
class NameMask
{
    public const MODES = ['full', 'first_initial', 'first_only'];
    private const TITLES = ['นาย', 'นาง', 'นางสาว', 'น.ส.', 'นส.', 'ด.ช.', 'ด.ญ.', 'เด็กชาย', 'เด็กหญิง', 'ดช.', 'ดญ.', 'mr', 'mr.', 'mrs', 'mrs.', 'miss', 'ms', 'ms.'];

    /** True when the text is only a title word (นาย, เด็กชาย, ...): searching for it alone would match half of a list. */
    public static function isTitle(string $s): bool
    {
        return in_array(mb_strtolower(trim($s)), self::TITLES, true);
    }

    public static function apply(string $name, string $mode): string
    {
        // Zero-width characters (pasted from Word/Excel) often sit between first name and surname: treat them as spaces.
        $name = trim((string) preg_replace('/[\s\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}]+/u', ' ', $name));
        if ($mode === 'full' || $name === '') return $name;
        $parts = explode(' ', $name);
        $prefix = '';
        if (count($parts) > 1 && in_array(mb_strtolower($parts[0]), self::TITLES, true)) $prefix = array_shift($parts).' ';
        $first = $prefix.$parts[0];
        if ($mode === 'first_only') return $first;
        if (count($parts) < 2) {
            // No surname token to abbreviate (no separator at all): fail closed instead of publishing the full name.
            $len = mb_strlen($parts[0]);
            return $prefix.mb_substr($parts[0], 0, max(1, min(3, intdiv($len, 2)))).'...';
        }
        // Surname initial: skip Thai leading vowels so the initial is the consonant that starts the syllable.
        $sur = $parts[1];
        $core = (string) preg_replace('/^[เแโใไ]+/u', '', $sur);
        $initial = mb_substr($core !== '' ? $core : $sur, 0, 1);
        return $first.' '.$initial.'.';
    }
}
