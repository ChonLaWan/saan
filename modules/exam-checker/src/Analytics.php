<?php

namespace Saan\Modules\ExamChecker;

/**
 * Item analysis and class summary over loaded sheets (objects with id, classroom_id, classroom_label, answers, score, section_scores).
 * Item analysis conventions (documented in the UI):
 *   p  = correct / sheets                      (difficulty index: higher = easier)
 *   D  = (upper correct - lower correct) / g   (discrimination); groups are the top and bottom g sheets by total score,
 *        g = max(1, round(0.27 * n)), ties broken by sheet id ascending; D is null when n < 2.
 *   cancelled questions are listed but excluded from p and D.
 */
final class Analytics
{
    public static function analysis(object $exam, iterable $sheets): array
    {
        $key = json_decode((string) $exam->answer_key, true) ?: [];
        $choices = (int) $exam->choices_per_question;
        $list = [];
        foreach ($sheets as $s) $list[] = $s;
        $n = count($list);
        $scorer = new Scorer($key, (float) $exam->points_per_question);

        usort($list, fn ($a, $b) => [(float) $b->score, (int) $a->id] <=> [(float) $a->score, (int) $b->id]);
        $g = $n >= 2 ? max(1, (int) round(0.27 * $n)) : 0;
        $items = [];
        $count = count($key);
        $counts = array_fill(0, $count, array_fill(1, $choices, 0));
        $blank = array_fill(0, $count, 0);
        $multi = array_fill(0, $count, 0);
        $right = array_fill(0, $count, 0);
        $upper = array_fill(0, $count, 0);
        $lower = array_fill(0, $count, 0);
        $byClass = [];

        foreach ($list as $pos => $s) {
            $hits = $scorer->hits((string) $s->answers);
            $label = (string) ($s->classroom_label ?? '');
            $cid = $s->classroom_id !== null ? (int) $s->classroom_id : 0;
            $byClass[$cid] ??= ['id' => $cid ?: null, 'label' => $label !== '' ? $label : 'ไม่ระบุห้อง', 'n' => 0, 'right' => array_fill(0, $count, 0)];
            $byClass[$cid]['n']++;
            $isUpper = $g && $pos < $g;
            $isLower = $g && $pos >= $n - $g;
            for ($i = 0; $i < $count; $i++) {
                $ch = $s->answers[$i] ?? '-';
                if ($ch === '-') $blank[$i]++; elseif ($ch === '*') $multi[$i]++; elseif (isset($counts[$i][(int) $ch])) $counts[$i][(int) $ch]++;
                if ($key[$i] !== 'cancelled' && $hits[$i]) {
                    $right[$i]++; $byClass[$cid]['right'][$i]++;
                    if ($isUpper) $upper[$i]++;
                    if ($isLower) $lower[$i]++;
                }
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $cancelled = $key[$i] === 'cancelled';
            $p = !$cancelled && $n ? round($right[$i] / $n, 4) : null;
            $d = !$cancelled && $g ? round(($upper[$i] - $lower[$i]) / $g, 4) : null;
            $accepted = $cancelled ? [] : (array) $key[$i];
            $topWrong = 0;
            foreach ($counts[$i] as $c => $v) if (!in_array($c, $accepted, true)) $topWrong = max($topWrong, $v);
            $items[] = [
                'q' => $i + 1, 'cancelled' => $cancelled, 'key' => $cancelled ? null : $accepted,
                'choices' => array_values($counts[$i]), 'blank' => $blank[$i], 'multiple' => $multi[$i],
                'correct' => $cancelled ? null : $right[$i], 'pct' => $p === null ? null : round($p * 100, 1),
                'p' => $p, 'p_label' => self::pLabel($p), 'd' => $d, 'd_label' => self::dLabel($d), 'upper_correct' => $cancelled ? null : $upper[$i], 'lower_correct' => $cancelled ? null : $lower[$i],
                'review' => !$cancelled && $n >= 5 && ($p < 0.2 || $p > 0.95 || ($d !== null && $d < 0.2) || $topWrong > $right[$i]),
            ];
        }
        $classes = [];
        foreach ($byClass as $c) {
            $classes[] = ['id' => $c['id'], 'label' => $c['label'], 'n' => $c['n'],
                'p' => array_map(fn ($r, $k) => $k === 'cancelled' ? null : round($r / $c['n'], 4), $c['right'], $key)];
        }
        usort($classes, fn ($a, $b) => strcmp($a['label'], $b['label']));
        return ['sheets' => $n, 'group_size' => $g, 'items' => $items, 'classrooms' => $classes];
    }

    public static function pLabel(?float $p): ?string
    {
        return $p === null ? null : ($p > 0.8 ? 'ง่าย' : ($p < 0.2 ? 'ยาก' : 'ปานกลาง'));
    }

    public static function dLabel(?float $d): ?string
    {
        return $d === null ? null : ($d >= 0.4 ? 'ดีมาก' : ($d >= 0.3 ? 'ดี' : ($d >= 0.2 ? 'พอใช้' : 'ควรปรับปรุง')));
    }

    public static function summary(object $exam, iterable $sheets): array
    {
        $max = round((int) $exam->question_count * (float) $exam->points_per_question, 2);
        $all = []; $classes = []; $secSum = []; $dist = array_fill(0, 10, 0);
        foreach ($sheets as $s) {
            $sc = (float) $s->score;
            $all[] = $sc;
            $cid = $s->classroom_id !== null ? (int) $s->classroom_id : 0;
            $classes[$cid]['label'] ??= (string) ($s->classroom_label ?: 'ไม่ระบุห้อง');
            $classes[$cid]['id'] ??= $cid ?: null;
            $classes[$cid]['scores'][] = $sc;
            $dist[$max > 0 ? min(9, (int) floor($sc / $max * 10)) : 0]++;
            foreach (json_decode((string) ($s->section_scores ?? ''), true) ?: [] as $i => $sec) {
                $secSum[$i] ??= ['label' => $sec['label'], 'sum' => 0.0, 'max' => $sec['max'], 'n' => 0];
                $secSum[$i]['sum'] += $sec['score']; $secSum[$i]['n']++;
            }
        }
        $bins = [];
        foreach ($dist as $i => $c) $bins[] = ['from' => $i * 10, 'to' => $i === 9 ? 100 : $i * 10 + 9.99, 'label' => ($i * 10).($i === 9 ? '-100' : '-'.($i * 10 + 9)).'%', 'count' => $c];
        $rows = [];
        foreach ($classes as $c) $rows[] = ['id' => $c['id'], 'label' => $c['label']] + self::stats($c['scores'], $max);
        usort($rows, fn ($a, $b) => strcmp($a['label'], $b['label']));
        return [
            'max_score' => $max, 'overall' => self::stats($all, $max), 'distribution' => $bins, 'classrooms' => $rows,
            'sections' => array_values(array_map(fn ($s) => ['label' => $s['label'], 'max' => $s['max'], 'mean' => round($s['sum'] / max(1, $s['n']), 2)], $secSum)),
        ];
    }

    /** 'sd' = sample S.D. (divide by n-1, as Excel STDEV; null when n < 2), 'sd_population' = divide by n. */
    public static function stats(array $scores, float $max): array
    {
        $n = count($scores);
        if ($n === 0) return ['n' => 0, 'mean' => null, 'sd' => null, 'sd_population' => null, 'min' => null, 'max' => null, 'median' => null, 'mean_pct' => null];
        $mean = array_sum($scores) / $n;
        $ss = array_sum(array_map(fn ($x) => ($x - $mean) ** 2, $scores));
        $var = $ss / $n;
        sort($scores);
        $mid = intdiv($n, 2);
        $median = $n % 2 ? $scores[$mid] : ($scores[$mid - 1] + $scores[$mid]) / 2;
        return ['n' => $n, 'mean' => round($mean, 2), 'sd' => $n > 1 ? round(sqrt($ss / ($n - 1)), 2) : null, 'sd_population' => round(sqrt($var), 2), 'min' => $scores[0], 'max' => $scores[$n - 1], 'median' => round($median, 2),
            'mean_pct' => $max > 0 ? round($mean / $max * 100, 1) : null];
    }
}
