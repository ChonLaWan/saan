<?php

namespace Saan\Modules\ExamChecker\Http;

use App\Support\Saan\Idempotency;
use App\Support\Saan\Mutation;
use App\Support\Saan\VersionConflict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Saan\Modules\ExamChecker\Access;
use Saan\Modules\ExamChecker\Importer;
use Saan\Modules\ExamChecker\Scoring;
use Saan\Modules\ExamChecker\Scorer;
use Saan\Modules\ExamChecker\SheetService;

class SheetController
{
    public function index(Request $r, int $id)
    {
        $exam = Access::exam($id);
        $f = $r->validate(['classroom_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:200']);
        $q = Access::sheets($exam, $f['classroom_id'] ?? null);
        $per = (int) ($f['per_page'] ?? 100);
        $total = (clone $q)->count();
        $rows = $q->orderBy('classroom_label')->orderBy('student_code')->forPage((int) ($f['page'] ?? 1), $per)->get();
        return response()->json(['data' => $rows->map(fn ($s) => SheetService::present($s))->values(), 'total' => $total, 'per_page' => $per]);
    }

    /** Save many sheets at once (entry grid). Body: {rows:[{student_code,answers,version?}], overwrite?} */
    public function store(Request $r, int $id)
    {
        $exam = $this->enterable($id);
        $d = $r->validate([
            'rows' => 'required|array|min:1|max:'.SheetService::MAX_ROWS, 'rows.*.student_code' => 'required|string|max:60', 'rows.*.answers' => 'nullable|string|max:600',
            'rows.*.version' => 'nullable|integer|min:1', 'rows.*.display_name' => 'nullable|string|max:200', 'overwrite' => 'nullable|boolean', 'allow_unknown' => 'nullable|boolean',
        ]);
        $rows = array_values(array_filter($d['rows'], fn ($x) => trim((string) ($x['answers'] ?? '')) !== ''));
        if (!$rows) abort(422, 'ไม่มีคำตอบที่จะบันทึก');
        return Idempotency::run($r, fn () => response()->json(SheetService::save($exam, $rows, [
            'overwrite' => !empty($d['overwrite']), 'allow_unknown' => !empty($d['allow_unknown']), 'source' => 'keyed',
        ])));
    }

    /** CSV import: multipart "file" or "csv" text. Options: dry_run, overwrite, allow_unknown. */
    public function import(Request $r, int $id)
    {
        $exam = $this->enterable($id);
        $d = $r->validate(['file' => 'nullable|file|max:4096', 'csv' => 'nullable|string|max:4000000', 'dry_run' => 'nullable|boolean', 'overwrite' => 'nullable|boolean', 'allow_unknown' => 'nullable|boolean']);
        $content = $r->hasFile('file') ? (string) file_get_contents($r->file('file')->getRealPath()) : (string) ($d['csv'] ?? '');
        if (trim($content) === '') abort(422, 'ไม่พบข้อมูล CSV');
        $parsed = Importer::parse($content, (int) $exam->question_count, (int) $exam->choices_per_question);
        $opts = ['overwrite' => !empty($d['overwrite']), 'allow_unknown' => !empty($d['allow_unknown']), 'source' => $parsed['source'], 'dry_run' => !empty($d['dry_run'])];
        $run = function () use ($exam, $parsed, $opts) {
            $out = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'saved' => [], 'errors' => [], 'preview' => []];
            // dedupe the WHOLE file before chunking (SheetService::save only sees one chunk): the first line of a student wins, later ones are errors
            $seen = [];
            $rows = [];
            foreach ($parsed['rows'] as $i => $row) {
                $code = trim((string) ($row['student_code'] ?? ''));
                $key = Access::codeKey($code);
                if ($code !== '' && isset($seen[$key])) {
                    $out['errors'][] = ['row' => $row['line'] ?? ($i + 1), 'student_code' => $code, 'message' => 'รหัสนักเรียนซ้ำในข้อมูลที่ส่ง'];
                    continue;
                }
                if ($code !== '') $seen[$key] = true;
                $rows[] = $row;
            }
            // one transaction for the whole file; a stale version in any chunk rolls everything back
            DB::transaction(function () use ($exam, $rows, $opts, &$out) {
                foreach (array_chunk($rows, 200) as $chunk) {
                    $res = SheetService::save($exam, $chunk, $opts);
                    foreach (['created', 'updated', 'unchanged'] as $k) $out[$k] += $res[$k];
                    $out['errors'] = array_merge($out['errors'], $res['errors']);
                    $out['preview'] = array_merge($out['preview'], $res['preview'] ?? []);
                }
            });
            return $out + ['format' => $parsed['format'], 'rows' => count($parsed['rows'])];
        };
        return Idempotency::run($r, fn () => response()->json($run()));
    }

    public function update(Request $r, int $id)
    {
        [$sheet, $exam] = $this->sheet($id);
        $d = $r->validate(['version' => 'required|integer|min:1', 'answers' => 'required|string|max:600']);
        [$ans, $count, $err] = Scoring::parseAnswers($d['answers'], (int) $exam->choices_per_question);
        if ($err) abort(422, $err);
        if ($count !== (int) $exam->question_count) abort(422, 'จำนวนคำตอบ '.$count.' ข้อ ไม่ตรงกับข้อสอบ '.$exam->question_count.' ข้อ');
        [$score, $sections] = Scorer::forExam($exam)->score($ans);
        $row = DB::transaction(function () use ($exam, $id, $d, $ans, $score, $sections, $sheet) {
            // refuse a score computed from a key that was changed since the exam row was read
            $v = DB::table('exm_exams')->where('school_id', Access::school())->where('id', $exam->id)->lockForUpdate()->value('version');
            if ((int) $v !== (int) $exam->version) throw new VersionConflict(null, 'เฉลยหรือการตั้งค่าข้อสอบเพิ่งถูกแก้ไข กรุณาโหลดใหม่');
            return Mutation::update('exm_sheets', $id, (int) $d['version'], [
                'answers' => $ans, 'score' => $score, 'section_scores' => $sections ?: null, 'recorded_by' => auth()->id(), 'source' => 'keyed',
            ], 'exam_checker.sheet_updated', ['exam_id' => (int) $exam->id, 'student_code' => $sheet->student_code]);
        });
        return response()->json(['sheet' => SheetService::present($row)]);
    }

    public function destroy(Request $r, int $id)
    {
        [$sheet, $exam] = $this->sheet($id);
        $version = (int) $r->validate(['version' => 'required|integer|min:1'])['version'];
        Mutation::delete('exm_sheets', $id, $version, 'exam_checker.sheet_deleted');
        return response()->json(['ok' => true]);
    }

    /** Exam must exist, be visible, have a complete key, and accept sheets (open; draft only for its manager). */
    private function enterable(int $id): object
    {
        $exam = Access::exam($id);
        $this->guardOpen($exam);
        return $exam;
    }

    private function guardOpen(object $exam): void
    {
        if ($exam->status === 'closed') abort(422, 'ข้อสอบนี้ปิดรับใบตอบแล้ว');
        if ($exam->status === 'draft' && !Access::manage($exam)) abort(404);
        if (!Scoring::keyIsComplete(json_decode((string) $exam->answer_key, true), (int) $exam->question_count)) abort(422, 'ข้อสอบนี้ยังไม่มีเฉลยครบทุกข้อ');
    }

    /** @return array{0:object,1:object} */
    private function sheet(int $id): array
    {
        $sheet = DB::table('exm_sheets')->where('school_id', Access::school())->where('id', $id)->first();
        if (!$sheet) abort(404);
        $exam = Access::exam((int) $sheet->exam_id);
        $this->guardOpen($exam);
        $allowed = Access::classroomIds($exam);
        if ($allowed !== null && !in_array((int) $sheet->classroom_id, $allowed, true)) abort(403, 'ไม่มีสิทธิ์แก้ใบตอบของห้องนี้');
        return [$sheet, $exam];
    }
}
