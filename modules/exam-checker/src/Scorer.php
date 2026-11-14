<?php

namespace Saan\Modules\ExamChecker;

/** Scores normalised answer strings against a key. Build once per exam, call score() per sheet. */
final class Scorer
{
    /** @var array<int, array<int,bool>|null> per question: accepted choices, null = cancelled */
    private array $accept = [];

    public function __construct(public readonly array $key, public readonly float $ppq, public readonly array $sections = [])
    {
        foreach ($key as $i => $e) $this->accept[$i] = $e === 'cancelled' ? null : array_fill_keys((array) $e, true);
    }

    public static function forExam(object $exam): self
    {
        return new self(json_decode((string) $exam->answer_key, true) ?: [], (float) $exam->points_per_question,
            json_decode((string) ($exam->sections ?? ''), true) ?: []);
    }

    /** @return bool[] per question (cancelled questions are always true) */
    public function hits(string $answers): array
    {
        $hits = [];
        foreach ($this->accept as $i => $set) {
            $ch = $answers[$i] ?? '-';
            $hits[$i] = $set === null || isset($set[$ch]);
        }
        return $hits;
    }

    /** @return array{0:float,1:array<int,array{label:string,score:float,max:float}>} */
    public function score(string $answers): array
    {
        $hits = $this->hits($answers);
        $right = 0;
        foreach ($hits as $h) if ($h) $right++;
        $sections = [];
        foreach ($this->sections as $s) {
            $c = 0;
            for ($q = (int) $s['from'] - 1; $q <= (int) $s['to'] - 1; $q++) if (!empty($hits[$q])) $c++;
            $sections[] = ['label' => (string) $s['label'], 'score' => round($c * $this->ppq, 2), 'max' => round(((int) $s['to'] - (int) $s['from'] + 1) * $this->ppq, 2)];
        }
        return [round($right * $this->ppq, 2), $sections];
    }

    public function max(): float
    {
        return round(count($this->key) * $this->ppq, 2);
    }
}
