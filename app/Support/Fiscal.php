<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Thai government fiscal year: 1 October to 30 September, labelled by the Buddhist era year in which it ENDS (2570 = 1 Oct 2026 to 30 Sep 2027).
 * Boundaries are evaluated in Asia/Bangkok and returned in the application time zone, the zone the database stores timestamps in.
 */
final class Fiscal
{
    public static function of(DateTimeInterface $d): int
    {
        $d = CarbonImmutable::instance($d)->setTimezone('Asia/Bangkok');
        return (int) $d->year + ((int) $d->month >= 10 ? 1 : 0) + 543;
    }

    public const MONTHS_TH = [1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน', 5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม', 9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'];

    /** Fiscal year (Buddhist era) of a calendar month given in the Buddhist era: October 2569 and September 2570 are both fiscal year 2570. */
    public static function ofMonth(int $month, int $beYear): int
    {
        return $beYear + ($month >= 10 ? 1 : 0);
    }

    /** The 12 months of a fiscal year in order, October first: [[month, Buddhist-era year], ...]. @return list<array{0:int,1:int}> */
    public static function months(int $fy): array
    {
        $out = [];
        foreach ([10, 11, 12, 1, 2, 3, 4, 5, 6, 7, 8, 9] as $m) $out[] = [$m, $m >= 10 ? $fy - 1 : $fy];
        return $out;
    }

    public static function current(): int
    {
        return self::of(CarbonImmutable::now('Asia/Bangkok'));
    }

    /** How many calendar years back a monthly report may be dated. */
    public const REPORT_SPAN = 20;

    /** Calendar year (Buddhist era) and month today in Asia/Bangkok. @return array{0:int,1:int} [year, month] */
    public static function today(): array
    {
        $n = CarbonImmutable::now('Asia/Bangkok');
        return [(int) $n->year + 543, (int) $n->month];
    }

    /** Buddhist-era years a monthly report may carry: [this year - REPORT_SPAN, this year]. @return array{0:int,1:int} */
    public static function reportYears(): array
    {
        $y = self::today()[0];
        return [$y - self::REPORT_SPAN, $y];
    }

    /** A month that is inside the allowed years and not in the future. */
    public static function reportMonthAllowed(int $month, int $beYear): bool
    {
        [$min, $max] = self::reportYears();
        [, $m] = self::today();
        return $month >= 1 && $month <= 12 && $beYear >= $min && $beYear <= $max && !($beYear === $max && $month > $m);
    }

    /** Fiscal years a public list may show even without a document: from the oldest allowed month to the current fiscal year. @return array{0:int,1:int} */
    public static function reportFiscalYears(): array
    {
        return [self::reportYears()[0], self::current()];
    }

    /**
     * Half-open range [from, to): from = 00:00:00 on 1 Oct of the year before, to = 00:00:00 on 1 Oct of the year itself.
     * Use "col >= from and col < to" so a value at 23:59:59.999 on 30 Sep is still inside.
     * @return array{0:string,1:string} Y-m-d H:i:s in the application time zone
     */
    public static function range(int $fy): array
    {
        $ce = $fy - 543;
        $tz = (string) config('app.timezone', 'UTC');
        $from = CarbonImmutable::create($ce - 1, 10, 1, 0, 0, 0, 'Asia/Bangkok')->setTimezone($tz);
        $to = CarbonImmutable::create($ce, 10, 1, 0, 0, 0, 'Asia/Bangkok')->setTimezone($tz);
        return [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];
    }
}
