<?php

namespace Saan\Modules\ReportsPp;

use Saan\Modules\Assessment\GradePolicy;

/** Pure report maths (no framework, no database). Integers only: credit in tenths, grade points in halves, GPA in hundredths. */
final class Calc
{
    public const SPECIAL = ['ร' => 'ไม่สมบูรณ์', 'มผ' => 'ไม่ผ่านเกณฑ์', 'มส' => 'ไม่มีสิทธิ์เข้ารับการประเมิน'];
    /** every grade bucket shown in the class summary, best first */
    public const BUCKETS = ['4', '3.5', '3', '2.5', '2', '1.5', '1', '0'];

    /** grade string -> grade point times two (3.5 -> 7), null for anything that is not a numeric grade */
    public static function pointsX2(?string $grade): ?int
    {
        return match ($grade) { '4' => 8, '3.5' => 7, '3' => 6, '2.5' => 5, '2' => 4, '1.5' => 3, '1' => 2, '0' => 0, default => null };
    }

    /** credit "1.5" / 1.5 / null -> tenths (15), null when missing or not positive */
    public static function creditTenths(mixed $credit): ?int
    {
        if ($credit === null || $credit === '') return null;
        $v = (int) round(((float) $credit) * 10);
        return $v > 0 ? $v : null;
    }

    /**
     * Result of one student in one course.
     * @param array<string,int|null> $centi component key => score_centi
     * @param array{code:string,note:?string}|null $special
     * @return array{state:string,grade:?string,total:?int,total_centi:?int,special:?string,missing:string[]}
     */
    public static function result(array $policy, array $centi, ?array $special): array
    {
        if ($special) return ['state' => 'special', 'grade' => $special['code'], 'total' => null, 'total_centi' => null, 'special' => $special['code'], 'missing' => []];
        $r = GradePolicy::compute($policy, $centi);
        if ($r['status'] !== 'complete') {
            return ['state' => count(array_filter($centi, fn ($v) => $v !== null)) === 0 ? 'none' : 'incomplete', 'grade' => null, 'total' => null, 'total_centi' => null, 'special' => null, 'missing' => $r['missing']];
        }
        return ['state' => 'graded', 'grade' => $r['grade'], 'total' => $r['total'], 'total_centi' => $r['total_centi'], 'special' => null, 'missing' => []];
    }

    /**
     * Credit-weighted GPA. Special results (ร/มผ/มส), incomplete rows and subjects without a positive credit are excluded.
     * @param array<int,array{credit:?int,grade:?string}> $items credit in tenths
     * @return array{gpa_centi:?int,credits_tenths:int,counted:int}
     */
    public static function gpa(array $items, string $mode = 'truncate'): array
    {
        $num = 0;
        $den = 0;
        $n = 0;
        foreach ($items as $i) {
            $p = self::pointsX2($i['grade'] ?? null);
            $c = $i['credit'] ?? null;
            if ($p === null || $c === null || $c <= 0) continue;
            $num += $c * $p;
            $den += $c;
            $n++;
        }
        if ($den === 0) return ['gpa_centi' => null, 'credits_tenths' => 0, 'counted' => 0];
        // gpa = (num / 2) / den; in hundredths = num * 100 / (2 * den). Default 'truncate' = Thai MOE practice (two decimals, no
        // rounding; values are non-negative so intdiv is a floor); 'round' = half up (school option).
        $centi = $mode === 'round' ? GradePolicy::half($num * 100, 2 * $den) : intdiv($num * 100, 2 * $den);
        return ['gpa_centi' => $centi, 'credits_tenths' => $den, 'counted' => $n];
    }

    /** Competition ranking (1,2,2,4) by GPA descending; students without GPA get null. @param array<int,?int> $gpaByStudent @return array<int,?int> */
    public static function ranks(array $gpaByStudent): array
    {
        $with = array_filter($gpaByStudent, fn ($v) => $v !== null);
        arsort($with);
        $out = array_fill_keys(array_keys($gpaByStudent), null);
        $pos = 0;
        $prev = null;
        $rank = 0;
        foreach ($with as $id => $g) {
            $pos++;
            if ($g !== $prev) { $rank = $pos; $prev = $g; }
            $out[$id] = $rank;
        }
        return $out;
    }

    /** Grade distribution of one course. @param array<int,array> $results Calc::result() rows */
    public static function distribution(array $results): array
    {
        $d = array_fill_keys(self::BUCKETS, 0) + ['ร' => 0, 'มผ' => 0, 'มส' => 0, 'incomplete' => 0, 'total' => count($results), 'sum_total' => 0, 'graded' => 0];
        foreach ($results as $r) {
            if ($r['state'] === 'graded') { $d[$r['grade']]++; $d['graded']++; $d['sum_total'] += (int) $r['total']; }
            elseif ($r['state'] === 'special') $d[$r['special']]++;
            else $d['incomplete']++;
        }
        return $d;
    }

    public static function fmtCenti(?int $c): string
    {
        return $c === null ? '-' : number_format($c / 100, 2, '.', '');
    }

    public static function fmtScore(?int $c): string
    {
        if ($c === null) return '';
        return rtrim(rtrim(number_format($c / 100, 2, '.', ''), '0'), '.');
    }

    public static function fmtCredit(?int $tenths): string
    {
        return $tenths === null ? '-' : number_format($tenths / 10, 1, '.', '');
    }

    /** CSV formula-injection guard for text cells. */
    public static function csvCell(mixed $v): string
    {
        $s = (string) $v;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($s)) $s = "'".$s;
        return $s;
    }
}
