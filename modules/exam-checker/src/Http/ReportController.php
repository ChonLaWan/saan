<?php

namespace Saan\Modules\ExamChecker\Http;

use App\Support\Saan\Mutation;
use Illuminate\Http\Request;
use Saan\Modules\ExamChecker\Access;
use Saan\Modules\ExamChecker\Analytics;
use Saan\Modules\ExamChecker\Scorer;
use Saan\Modules\ExamChecker\Scoring;

class ReportController
{
    private function load(Request $r, int $id): array
    {
        $exam = Access::exam($id);
        $cid = $r->validate(['classroom_id' => 'nullable|integer|min:1'])['classroom_id'] ?? null;
        return [$exam, Access::sheets($exam, $cid ? (int) $cid : null), $cid ? (int) $cid : null];
    }

    public function analysis(Request $r, int $id)
    {
        [$exam, $q] = $this->load($r, $id);
        $sheets = $q->get(['id', 'classroom_id', 'classroom_label', 'answers', 'score']);
        return response()->json(['exam' => ['id' => (int) $exam->id, 'title' => $exam->title, 'question_count' => (int) $exam->question_count, 'choices_per_question' => (int) $exam->choices_per_question]]
            + Analytics::analysis($exam, $sheets));
    }

    public function summary(Request $r, int $id)
    {
        [$exam, $q] = $this->load($r, $id);
        $sheets = $q->get(['id', 'classroom_id', 'classroom_label', 'score', 'section_scores']);
        return response()->json(['exam' => ['id' => (int) $exam->id, 'title' => $exam->title]] + Analytics::summary($exam, $sheets));
    }

    public function export(Request $r, int $id)
    {
        [$exam, $q] = $this->load($r, $id);
        $sheets = $q->orderBy('classroom_label')->orderBy('student_code')->get();
        $secLabels = array_column(json_decode((string) ($exam->sections ?? ''), true) ?: [], 'label');
        $max = round((int) $exam->question_count * (float) $exam->points_per_question, 2);
        $out = "\xEF\xBB\xBF".self::line(array_merge(['ห้อง', 'รหัสนักเรียน', 'ชื่อ', 'คะแนน', 'คะแนนเต็ม', 'ร้อยละ'], $secLabels, ['คำตอบ']));
        foreach ($sheets as $s) {
            $sec = array_column(json_decode((string) ($s->section_scores ?? ''), true) ?: [], 'score');
            $out .= self::line(array_merge([$s->classroom_label, $s->student_code, $s->display_name, (float) $s->score, $max, $max > 0 ? round($s->score / $max * 100, 1) : ''],
                array_pad($sec, count($secLabels), ''), [$s->answers]));
        }
        Mutation::audit('exam_checker.exported', 'exm_exams', (int) $exam->id, ['rows' => count($sheets)]);
        return response($out, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="exam-'.(int) $exam->id.'-scores.csv"', 'Cache-Control' => 'no-store, private']);
    }

    /** Printable A4 report slips: one per sheet. ?sheet=ID for one student, else every visible sheet (max 300). */
    public function slips(Request $r, int $id)
    {
        [$exam, $q] = $this->load($r, $id);
        $one = $r->validate(['sheet' => 'nullable|integer|min:1'])['sheet'] ?? null;
        if ($one) $q->where('id', $one);
        $sheets = $q->orderBy('classroom_label')->orderBy('student_code')->limit(300)->get();
        $scorer = Scorer::forExam($exam);
        $key = $scorer->key;
        $max = $scorer->max();
        $slips = $sheets->map(function ($s) use ($scorer, $key, $max) {
            $hits = $scorer->hits((string) $s->answers);
            $wrong = [];
            foreach ($hits as $i => $ok) {
                if ($ok) continue;
                $ch = $s->answers[$i] ?? '-';
                $wrong[] = ['q' => $i + 1, 'chosen' => $ch === '-' ? 'ไม่ตอบ' : ($ch === '*' ? 'ตอบหลายข้อ' : Scoring::LETTERS[(int) $ch - 1]),
                    'correct' => implode(' หรือ ', array_map(fn ($c) => Scoring::LETTERS[$c - 1], (array) $key[$i]))];
            }
            return ['sheet' => $s, 'wrong' => $wrong, 'sections' => json_decode((string) ($s->section_scores ?? ''), true) ?: [],
                'percent' => $max > 0 ? round($s->score / $max * 100, 1) : 0];
        });
        return response()->view('exam-checker::slip', ['exam' => $exam, 'slips' => $slips, 'max' => $max])->header('Cache-Control', 'no-store, private');
    }

    private static function line(array $cells): string
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, array_map(function ($v) {
            $s = (string) ($v ?? '');
            return $s !== '' && strpbrk($s[0], "=+-@\t\r") !== false && !is_numeric($s) ? "'".$s : $s;
        }, $cells), ',', '"', '');
        rewind($h);
        $o = stream_get_contents($h);
        fclose($h);
        return $o;
    }
}
