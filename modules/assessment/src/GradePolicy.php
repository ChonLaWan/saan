<?php

namespace Saan\Modules\Assessment;

use InvalidArgumentException;

/**
 * Pure grade computation, no framework or database access. All maths is integer (centi = hundredths).
 *
 * policy = {components:[{key,label,max,group:'continuous'|'exam'}], continuous_ratio, exam_ratio,
 *           rounding:'half_up', pass_mark}
 * total_centi = (sum continuous centi * continuous_ratio / sum continuous max)
 *             + (sum exam centi * exam_ratio / sum exam max), an exact rational in hundredths of a point.
 * Rounding rule (single rounding, decision to be confirmed by the school): total_centi is that value rounded half-up to
 * hundredths for display; the reported whole total and the 8-level grade come from the SAME exact value rounded half-up
 * once to a whole point (79.50 -> 80 -> 4, 79.49 -> 79 -> 3.5, exact 79.495 -> 79 -> 3.5). For the default 70/30 policy
 * no fractional hundredths exist, so this equals the prototype ScoreCalculator.
 */
final class GradePolicy
{
    public const GROUPS = ['continuous', 'exam'];
    /** lower bound (whole points) => grade */
    /** whole points; the lowest passing level of LEVELS (single source of truth for "passed") */
    public const PASS_MARK = 50;
    public const LEVELS =[80 => '4', 75 => '3.5', 70 => '3', 65 => '2.5', 60 => '2', 55 => '1.5', 50 => '1'];

    public static function defaults(): array
    {
        return [
            'components' => [
                ['key' => 'continuous', 'label' => 'คะแนนระหว่างภาค', 'max' => 70, 'group' => 'continuous'],
                ['key' => 'exam', 'label' => 'คะแนนปลายภาค', 'max' => 30, 'group' => 'exam'],
            ],
            'continuous_ratio' => 70, 'exam_ratio' => 30, 'rounding' => 'half_up', 'pass_mark' => 50,
        ];
    }

    /** Validates and normalises a policy; throws InvalidArgumentException with a Thai message. */
    public static function normalize(?array $policy): array
    {
        $policy ??= self::defaults();
        $components = $policy['components'] ?? null;
        if (!is_array($components) || $components === [] || count($components) > 12) throw new InvalidArgumentException('ต้องมีองค์ประกอบคะแนน 1-12 รายการ');
        $out = [];
        $seen = [];
        foreach (array_values($components) as $c) {
            $key = is_array($c) ? (string) ($c['key'] ?? '') : '';
            if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key)) throw new InvalidArgumentException('รหัสองค์ประกอบคะแนนต้องเป็น a-z 0-9 _ ไม่เกิน 40 ตัว');
            if (isset($seen[$key])) throw new InvalidArgumentException('รหัสองค์ประกอบคะแนนซ้ำ: '.$key);
            $seen[$key] = true;
            $max = $c['max'] ?? null;
            if (!is_int($max) && !(is_string($max) && ctype_digit($max))) throw new InvalidArgumentException('คะแนนเต็มต้องเป็นจำนวนเต็ม');
            $max = (int) $max;
            if ($max < 1 || $max > 1000) throw new InvalidArgumentException('คะแนนเต็มต้องอยู่ระหว่าง 1-1000');
            $group = (string) ($c['group'] ?? 'continuous');
            if (!in_array($group, self::GROUPS, true)) throw new InvalidArgumentException('กลุ่มคะแนนต้องเป็น continuous หรือ exam');
            $label = trim((string) ($c['label'] ?? ''));
            if ($label === '' || mb_strlen($label) > 80) throw new InvalidArgumentException('ต้องระบุชื่อองค์ประกอบคะแนน (ไม่เกิน 80 ตัวอักษร)');
            $out[] = ['key' => $key, 'label' => $label, 'max' => $max, 'group' => $group];
        }
        $cr = self::ratio($policy['continuous_ratio'] ?? null);
        $er = self::ratio($policy['exam_ratio'] ?? null);
        if ($cr + $er !== 100) throw new InvalidArgumentException('สัดส่วนคะแนนระหว่างภาคและปลายภาครวมกันต้องเป็น 100');
        foreach ([['continuous', $cr], ['exam', $er]] as [$g, $r]) {
            $has = count(array_filter($out, fn ($c) => $c['group'] === $g)) > 0;
            if ($r > 0 && !$has) throw new InvalidArgumentException('กลุ่ม '.$g.' มีสัดส่วนแต่ไม่มีองค์ประกอบคะแนน');
            if ($r === 0 && $has) throw new InvalidArgumentException('กลุ่ม '.$g.' มีองค์ประกอบคะแนนแต่สัดส่วนเป็น 0');
        }
        if (($policy['rounding'] ?? 'half_up') !== 'half_up') throw new InvalidArgumentException('รองรับการปัดแบบ half_up เท่านั้น');
        $pass = $policy['pass_mark'] ?? 50;
        if (!is_int($pass) && !(is_string($pass) && ctype_digit($pass))) throw new InvalidArgumentException('เกณฑ์ผ่านต้องเป็นจำนวนเต็ม');
        $pass = (int) $pass;
        if ($pass < 0 || $pass > 100) throw new InvalidArgumentException('เกณฑ์ผ่านต้องอยู่ระหว่าง 0-100');
        // The 8-level grade table is fixed (grade 0 below 50), so "passed" and the grade must agree: pass_mark is accepted for old
        // stored policies but always normalised to PASS_MARK; a different value would make the grid and ปพ.5/ปพ.6 contradict each other.
        return ['components' => $out, 'continuous_ratio' => $cr, 'exam_ratio' => $er, 'rounding' => 'half_up', 'pass_mark' => self::PASS_MARK];
    }

    private static function ratio(mixed $v): int
    {
        if (!is_int($v) && !(is_string($v) && ctype_digit($v))) throw new InvalidArgumentException('สัดส่วนคะแนนต้องเป็นจำนวนเต็ม 0-100');
        $v = (int) $v;
        if ($v < 0 || $v > 100) throw new InvalidArgumentException('สัดส่วนคะแนนต้องเป็นจำนวนเต็ม 0-100');
        return $v;
    }

    /** "12", "12.5", "12.50" -> centi; anything else (blank, comma, 3 decimals, negative, over max) throws. */
    public static function parseCenti(mixed $value, int $max): int
    {
        if (is_int($value)) $value = (string) $value;
        elseif (is_float($value)) $value = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        if (!is_string($value) || !preg_match('/^\d{1,4}(?:\.\d{1,2})?$/D', $value)) throw new InvalidArgumentException('กรอกคะแนนเป็นตัวเลขไม่เกินสองตำแหน่ง และห้ามแทนค่าว่างด้วยศูนย์');
        [$whole, $frac] = array_pad(explode('.', $value), 2, '');
        $centi = ((int) $whole) * 100 + (int) str_pad($frac, 2, '0');
        if ($centi > $max * 100) throw new InvalidArgumentException('คะแนนเกินคะแนนเต็ม ('.$max.')');
        return $centi;
    }

    public static function half(int $numerator, int $denominator): int
    {
        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }

    public static function gradeFor(int $wholePoints): string
    {
        foreach (self::LEVELS as $min => $grade) if ($wholePoints >= $min) return $grade;
        return '0';
    }

    /**
     * @param array<string,int|null> $centiByKey component key => score_centi (missing/null = not entered)
     * @return array{status:string,total_centi:?int,total:?int,grade:?string,passed:?bool,missing:string[]}
     */
    public static function compute(array $policy, array $centiByKey): array
    {
        $policy = self::normalize($policy);
        $sum = ['continuous' => 0, 'exam' => 0];
        $max = ['continuous' => 0, 'exam' => 0];
        $missing = [];
        foreach ($policy['components'] as $c) {
            $max[$c['group']] += $c['max'];
            $v = $centiByKey[$c['key']] ?? null;
            if ($v === null) { $missing[] = $c['key']; continue; }
            $sum[$c['group']] += (int) $v;
        }
        if ($missing) return ['status' => 'incomplete', 'total_centi' => null, 'total' => null, 'grade' => null, 'passed' => null, 'missing' => $missing];
        $cr = $policy['continuous_ratio'];
        $er = $policy['exam_ratio'];
        // exact rational: cp = sum_c*cr/max_c, ep = sum_e*er/max_e, both in hundredths of a point
        if ($max['continuous'] > 0 && $max['exam'] > 0) {
            $den = $max['continuous'] * $max['exam'];
            $num = $sum['continuous'] * $cr * $max['exam'] + $sum['exam'] * $er * $max['continuous'];
        } elseif ($max['continuous'] > 0) {
            $den = $max['continuous'];
            $num = $sum['continuous'] * $cr;
        } else {
            $den = $max['exam'];
            $num = $sum['exam'] * $er;
        }
        // single rounding: the whole point comes from the exact rational $num/$den (hundredths), never from the rounded
        // hundredths (79.495 -> 79, not 79.50 -> 80). total_centi is for display only.
        $total = self::half($num, $den);
        $whole = self::half($num, $den * 100);
        return ['status' => 'complete', 'total_centi' => $total, 'total' => $whole, 'grade' => self::gradeFor($whole), 'passed' => self::gradeFor($whole) !== '0', 'missing' => []];
    }
}
