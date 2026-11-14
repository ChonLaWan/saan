<?php

namespace Saan\Modules\Finance;

use InvalidArgumentException;

/** Integer-satang money helpers: formatting, parsing and Thai baht text. Never uses floats. */
final class Baht
{
    private const DIGITS = ['ศูนย์', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
    private const PLACES = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน'];

    /** 123456 -> "1,234.56" */
    public static function format(int $satang): string
    {
        $abs = abs($satang);
        return ($satang < 0 ? '-' : '').number_format(intdiv($abs, 100), 0, '.', ',').'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** 123456 -> "1234.56" (CSV) */
    public static function plain(int $satang): string
    {
        $abs = abs($satang);
        return ($satang < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "1,234.5" -> 123450. Accepts at most two decimals; anything else throws. */
    public static function toSatang(string $text): int
    {
        if (!preg_match('/^\s*(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,2}))?\s*$/D', $text, $m)) throw new InvalidArgumentException('จำนวนเงินไม่ถูกต้อง');
        $baht = str_replace(',', '', $m[1]);
        if (strlen($baht) > 12) throw new InvalidArgumentException('จำนวนเงินมากเกินไป');
        return (int) $baht * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** 10000 -> "หนึ่งร้อยบาทถ้วน", 12550 -> "หนึ่งร้อยยี่สิบห้าบาทห้าสิบสตางค์" */
    public static function text(int $satang): string
    {
        if ($satang < 0) return 'ลบ'.self::text(-$satang);
        $baht = intdiv($satang, 100);
        $st = $satang % 100;
        if ($baht === 0 && $st === 0) return 'ศูนย์บาทถ้วน';
        $out = $baht > 0 ? self::integer($baht).'บาท' : '';
        return $out.($st === 0 ? 'ถ้วน' : self::chunk($st, false).'สตางค์');
    }

    private static function integer(int $n): string
    {
        if ($n >= 1000000) {
            $rest = $n % 1000000;
            return self::integer(intdiv($n, 1000000)).'ล้าน'.($rest > 0 ? self::chunk($rest, true) : '');
        }
        return self::chunk($n, false);
    }

    /** 1..999999. A units digit of 1 is "เอ็ด" when anything stands before it. */
    private static function chunk(int $n, bool $hasHigher): string
    {
        $digits = str_split((string) $n);
        $len = count($digits);
        $out = '';
        foreach ($digits as $i => $ch) {
            $d = (int) $ch;
            $p = $len - 1 - $i;
            if ($d === 0) continue;
            if ($p === 1) $out .= $d === 1 ? 'สิบ' : ($d === 2 ? 'ยี่สิบ' : self::DIGITS[$d].'สิบ');
            elseif ($p === 0) $out .= ($d === 1 && ($len > 1 || $hasHigher)) ? 'เอ็ด' : self::DIGITS[$d];
            else $out .= self::DIGITS[$d].self::PLACES[$p];
        }
        return $out;
    }
}
