<?php

namespace Saan\Modules\ExamChecker\Http;

use App\Support\Saan\Idempotency;
use App\Support\Saan\Mutation;
use App\Support\Saan\TeacherScope;
use App\Support\Saan\TermScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Saan\Modules\ExamChecker\Access;
use Saan\Modules\ExamChecker\Scoring;
use Saan\Modules\ExamChecker\SheetService;

class ExamController
{
    public const KINDS = ['midterm' => 'กลางภาค', 'final' => 'ปลายภาค', 'onet_practice' => 'ฝึกทำ O-NET', 'quiz' => 'แบบทดสอบย่อย', 'other' => 'อื่น ๆ'];

    public function index(Request $r)
    {
        $f = $r->validate(['status' => ['nullable', Rule::in(['draft', 'open', 'closed'])], 'kind' => ['nullable', Rule::in(array_keys(self::KINDS))], 'all_terms' => 'nullable|boolean']);
        $user = $r->user();
        $q = DB::table('exm_exams')->where('school_id', Access::school())->orderByDesc('id');
        if (empty($f['all_terms'])) {
            $term = TermScope::currentId();
            $q->where(fn ($w) => $term ? $w->where('term_id', $term)->orWhereNull('term_id') : $w->whereNull('term_id'));
        }
        if (!empty($f['status'])) $q->where('status', $f['status']);
        if (!empty($f['kind'])) $q->where('kind', $f['kind']);
        if (!in_array($user->role, ['admin', 'principal'], true)) $q->where(fn ($w) => $w->where('created_by', $user->id)->orWhere('status', '!=', 'draft'));
        $exams = $q->limit(300)->get();
        // counts and means only cover the classrooms the user may see (TeacherScope depends on the exam's term)
        $stats = collect();
        foreach ($exams->groupBy(fn ($e) => (int) $e->term_id) as $term => $group) {
            $ids = Access::scopeFor($term ?: null);
            $sq = DB::table('exm_sheets')->where('school_id', Access::school())->whereIn('exam_id', $group->pluck('id'));
            if ($ids !== null) $sq->whereIn('classroom_id', $ids ?: [0]);
            $stats = $stats->union($sq->groupBy('exam_id')->selectRaw('exam_id, count(*) as n, avg(score) as mean')->get()->keyBy('exam_id'));
        }
        return response()->json(['data' => $exams->map(fn ($e) => $this->present($e) + [
            'sheet_count' => (int) ($stats[$e->id]->n ?? 0), 'mean' => isset($stats[$e->id]) ? round((float) $stats[$e->id]->mean, 2) : null,
        ])->values(), 'kinds' => self::KINDS]);
    }

    public function show(int $id)
    {
        $exam = Access::exam($id);
        return response()->json(['exam' => $this->present($exam, true), 'kinds' => self::KINDS]);
    }

    public function store(Request $r)
    {
        $d = $this->validated($r, true);
        $user = $r->user();
        return Idempotency::run($r, function () use ($d, $user) {
            $term = array_key_exists('term_id', $d) ? $d['term_id'] : TermScope::currentId();
            $row = Mutation::insert('exm_exams', [
                'term_id' => $term, 'title' => $d['title'], 'kind' => $d['kind'], 'subject_label' => $d['subject_label'] ?? null, 'grade_level' => $d['grade_level'] ?? null,
                'question_count' => $d['question_count'], 'choices_per_question' => $d['choices_per_question'], 'points_per_question' => $d['points_per_question'],
                'answer_key' => $d['_key'], 'sections' => $d['_sections'] ?? null, 'status' => 'draft', 'created_by' => $user->id,
            ], 'exam_checker.exam_created', ['title' => $d['title']]);
            return response()->json(['exam' => $this->present($row, true)], 201);
        });
    }

    public function update(Request $r, int $id)
    {
        $exam = $this->manageable($id);
        $d = $this->validated($r, false, $exam);
        $version = (int) $r->validate(['version' => 'required|integer|min:1'])['version'];
        $changes = array_intersect_key($d, array_flip(['title', 'kind', 'subject_label', 'grade_level', 'question_count', 'choices_per_question', 'points_per_question', 'status', 'term_id']));
        if (array_key_exists('_key', $d)) $changes['answer_key'] = $d['_key'];
        if (array_key_exists('_sections', $d)) $changes['sections'] = $d['_sections'];
        if (($changes['status'] ?? $exam->status) === 'open') {
            $key = array_key_exists('_key', $d) ? $d['_key'] : json_decode((string) $exam->answer_key, true);
            if (!Scoring::keyIsComplete($key, (int) ($changes['question_count'] ?? $exam->question_count))) abort(422, 'ต้องใส่เฉลยให้ครบทุกข้อก่อนเปิดรับใบตอบ');
        }
        $scoring = array_intersect_key($changes, array_flip(['answer_key', 'points_per_question', 'sections']));
        $rescore = null;
        $updated = DB::transaction(function () use ($id, $version, $changes, $exam, $scoring, $d, &$rescore) {
            // SheetService::save takes this same exam row lock first, so "has sheets" is decided under the lock and cannot race a sheet save
            DB::table('exm_exams')->where('school_id', Access::school())->where('id', $id)->lockForUpdate()->first(['id']);
            $hasSheets = DB::table('exm_sheets')->where('school_id', Access::school())->where('exam_id', $id)->exists();
            if ($hasSheets && array_key_exists('_key', $d) && $d['_key'] === null) abort(422, 'มีใบตอบแล้ว ลบเฉลยไม่ได้');
            foreach (['question_count', 'choices_per_question'] as $f) {
                if ($hasSheets && array_key_exists($f, $d) && (int) $d[$f] !== (int) $exam->{$f}) abort(422, 'มีใบตอบแล้ว จึงเปลี่ยนจำนวนข้อหรือจำนวนตัวเลือกไม่ได้');
            }
            $row = Mutation::update('exm_exams', $id, $version, $changes, 'exam_checker.exam_updated', ['fields' => array_keys($changes)]);
            $changedScoring = false;
            foreach ($scoring as $k => $v) {
                $diff = $k === 'points_per_question' ? abs((float) $exam->{$k} - (float) $v) > 0.001
                    : (json_decode((string) $exam->{$k}, true) ?: null) != ($v ?: null);
                $changedScoring = $changedScoring || $diff;
            }
            if ($hasSheets && $changedScoring) {
                $rescore = SheetService::rescore($row);
                Mutation::audit('exam_checker.rescored', 'exm_exams', $id, $rescore + ['reason' => 'scoring settings changed']);
            }
            return $row;
        });
        return response()->json(['exam' => $this->present($updated, true), 'rescored' => $rescore]);
    }

    public function rescore(Request $r, int $id)
    {
        $exam = $this->manageable($id);
        if (!Scoring::keyIsComplete(json_decode((string) $exam->answer_key, true), (int) $exam->question_count)) abort(422, 'ยังไม่มีเฉลยครบทุกข้อ');
        $res = DB::transaction(function () use ($exam) {
            $res = SheetService::rescore($exam);
            Mutation::audit('exam_checker.rescored', 'exm_exams', (int) $exam->id, $res + ['reason' => 'manual']);
            return $res;
        });
        return response()->json($res);
    }

    public function destroy(Request $r, int $id)
    {
        $exam = $this->manageable($id);
        $version = (int) $r->validate(['version' => 'required|integer|min:1'])['version'];
        if (DB::table('exm_sheets')->where('school_id', Access::school())->where('exam_id', $id)->exists()) abort(422, 'มีใบตอบแล้ว ลบข้อสอบไม่ได้ ให้ปิดข้อสอบแทน');
        Mutation::delete('exm_exams', $id, $version, 'exam_checker.exam_deleted');
        return response()->json(['ok' => true]);
    }

    public function classrooms(int $id)
    {
        $exam = Access::exam($id);
        $ids = Access::classroomIds($exam);
        $q = DB::table('classrooms')->where('school_id', Access::school())->orderBy('name');
        if (Schema::hasColumn('classrooms', 'is_active')) $q->where('is_active', true);
        if ($ids !== null) $q->whereIn('id', $ids ?: [0]);
        return response()->json(['data' => $q->get(['id', 'name'])->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->values()]);
    }

    /** Optional hint list from the assessment module (no hard dependency). */
    public function subjects()
    {
        $names = [];
        if (Schema::hasTable('asm_subjects') && Schema::hasColumn('asm_subjects', 'name')) {
            $q = DB::table('asm_subjects')->where('school_id', Access::school())->orderBy('name');
            if (Schema::hasColumn('asm_subjects', 'is_active')) $q->where('is_active', true);
            $names = $q->limit(200)->pluck('name')->all();
        }
        return response()->json(['data' => $names]);
    }

    /** Students of one classroom with their sheet for this exam (entry grid). */
    public function roster(Request $r, int $id)
    {
        $exam = Access::exam($id);
        $cid = (int) $r->validate(['classroom_id' => 'required|integer|min:1'])['classroom_id'];
        if (!DB::table('classrooms')->where('school_id', Access::school())->where('id', $cid)->exists()) abort(404);
        if (!Access::canClassroom($exam, $cid)) abort(403, 'ไม่มีสิทธิ์ดูห้องนี้');
        $q = DB::table('students')->where('school_id', Access::school())->where('classroom_id', $cid);
        if (Schema::hasColumn('students', 'status')) $q->where('status', 'studying');
        $order = Schema::hasColumn('students', 'roll_number') ? ['roll_number', 'code'] : ['code'];
        foreach ($order as $o) $q->orderBy($o);
        $students = $q->limit(200)->get(['id', 'code', 'name']);
        $sheets = $students->isEmpty() ? collect() : DB::table('exm_sheets')->where('school_id', Access::school())->where('exam_id', $id)
            ->whereIn('student_code_key', $students->map(fn ($s) => Access::codeKey((string) $s->code))->all())->get()->keyBy('student_code_key');
        return response()->json(['data' => $students->map(function ($s) use ($sheets) {
            $sh = $sheets[Access::codeKey((string) $s->code)] ?? null;
            return ['student_id' => (int) $s->id, 'code' => $s->code, 'name' => $s->name, 'sheet' => $sh ? SheetService::present($sh) : null];
        })->values()]);
    }

    private function manageable(int $id): object
    {
        $exam = Access::exam($id);
        if (!Access::manage($exam)) abort(403, 'แก้ไขได้เฉพาะผู้สร้างข้อสอบหรือผู้ดูแล');
        return $exam;
    }

    public function present(object $e, bool $detail = false): array
    {
        $key = json_decode((string) $e->answer_key, true);
        $manage = Access::manage($e);
        $out = [
            'id' => (int) $e->id, 'term_id' => $e->term_id ? (int) $e->term_id : null, 'title' => $e->title, 'kind' => $e->kind, 'kind_label' => self::KINDS[$e->kind] ?? $e->kind,
            'subject_label' => $e->subject_label, 'grade_level' => $e->grade_level, 'question_count' => (int) $e->question_count,
            'choices_per_question' => (int) $e->choices_per_question, 'points_per_question' => (float) $e->points_per_question,
            'max_score' => round((int) $e->question_count * (float) $e->points_per_question, 2), 'status' => $e->status, 'version' => (int) $e->version,
            'key_complete' => Scoring::keyIsComplete($key, (int) $e->question_count), 'can_manage' => $manage,
            'sections' => json_decode((string) ($e->sections ?? ''), true) ?: [],
        ];
        if ($detail && $manage) $out += ['answer_key' => Scoring::keyToString($key), 'key_length' => is_array($key) ? count($key) : 0];
        // anyone who can enter sheets already sees the key (item analysis, slips), so the grid may score live in the browser
        if ($detail && ($e->status !== 'draft' || $manage) && is_array($key)) $out['key'] = $key;
        return $out;
    }

    private function validated(Request $r, bool $creating, ?object $exam = null): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $d = $r->validate([
            'title' => [$req, 'string', 'max:200'], 'kind' => [$req, Rule::in(array_keys(self::KINDS))], 'subject_label' => 'nullable|string|max:120', 'grade_level' => 'nullable|string|max:40',
            'question_count' => [$req, 'integer', 'between:1,200'], 'choices_per_question' => [$creating ? 'sometimes' : 'sometimes', 'integer', 'between:2,6'],
            'points_per_question' => ['sometimes', 'numeric', 'min:0.01', 'max:100'], 'term_id' => 'sometimes|nullable|integer|min:1',
            'answer_key' => 'sometimes|nullable', 'sections' => 'sometimes|nullable|array|max:20', 'sections.*.label' => 'required|string|max:60',
            'sections.*.from' => 'required|integer|min:1', 'sections.*.to' => 'required|integer|min:1', 'status' => ['sometimes', Rule::in(['draft', 'open', 'closed'])],
        ]);
        $d['choices_per_question'] ??= $exam ? (int) $exam->choices_per_question : 4;
        $d['points_per_question'] = round((float) ($d['points_per_question'] ?? ($exam ? $exam->points_per_question : 1)), 2);
        $count = (int) ($d['question_count'] ?? $exam?->question_count ?? 0);
        $choices = (int) $d['choices_per_question'];
        if (!empty($d['term_id']) && !DB::table('academic_terms')->where('school_id', Access::school())->where('id', $d['term_id'])->exists()) abort(422, 'ไม่พบภาคเรียน');
        // the exam's term decides the creator's classroom scope: a teacher may only pick the current term (or keep the exam's own), never an old term they once taught in
        if (!empty($d['term_id']) && !in_array($r->user()->role, TeacherScope::ALL_ROLES, true)
            && (int) $d['term_id'] !== (int) TermScope::currentId() && (int) $d['term_id'] !== (int) ($exam->term_id ?? 0)) {
            abort(422, 'ครูเลือกได้เฉพาะภาคเรียนปัจจุบัน ภาคเรียนที่ผ่านมาให้ผู้ดูแลเป็นผู้ตั้ง');
        }
        // term_id decides which classrooms (homeroom included) can see the sheets: a teacher can neither move an existing exam to another term nor clear it, and a new exam never stays term-less
        if (array_key_exists('term_id', $d) && !in_array($r->user()->role, TeacherScope::ALL_ROLES, true)) {
            if ($exam) {
                if ((int) $d['term_id'] !== (int) $exam->term_id) abort(422, 'ครูเปลี่ยนภาคเรียนของข้อสอบไม่ได้ ให้ผู้ดูแลเป็นผู้ตั้ง');
            } elseif (empty($d['term_id'])) {
                $d['term_id'] = TermScope::currentId();
            }
        }
        $d['_key'] = null;
        if (array_key_exists('answer_key', $d) && $d['answer_key'] !== null && $d['answer_key'] !== '') {
            $raw = $d['answer_key'];
            [$entries, $err] = is_array($raw) ? Scoring::normalizeKey($raw, $choices) : Scoring::parseKey((string) $raw, $choices);
            if ($err) abort(422, $err);
            if (count($entries) !== $count) abort(422, 'เฉลยมี '.count($entries).' ข้อ แต่ข้อสอบมี '.$count.' ข้อ');
            $d['_key'] = $entries;
        } elseif (!$creating && !array_key_exists('answer_key', $d)) {
            unset($d['_key']);
            // changing the choice count must not leave a stored key that no valid answer can match
            if ($r->has('choices_per_question') && $exam && is_array($old = json_decode((string) $exam->answer_key, true)) && $old) {
                if (Scoring::normalizeKey($old, $choices)[1]) abort(422, 'เฉลยเดิมมีตัวเลือกเกินจำนวนใหม่ กรุณาใส่เฉลยใหม่');
            }
            // key length must still match when only the question count changes
            if (array_key_exists('question_count', $d) && $exam && ($k = json_decode((string) $exam->answer_key, true)) && count($k) !== $count) abort(422, 'เปลี่ยนจำนวนข้อแล้วต้องใส่เฉลยใหม่ให้ครบ');
        }
        if (array_key_exists('sections', $d)) {
            $secs = [];
            foreach ((array) $d['sections'] as $s) {
                if ($s['from'] > $s['to'] || $s['to'] > $count) abort(422, 'ช่วงข้อของส่วน "'.$s['label'].'" ไม่ถูกต้อง (1-'.$count.')');
                $secs[] = ['label' => trim($s['label']), 'from' => (int) $s['from'], 'to' => (int) $s['to']];
            }
            $d['_sections'] = $secs ?: null;
        } elseif ($exam && array_key_exists('question_count', $d) && $exam->sections) {
            foreach (json_decode($exam->sections, true) as $s) if ($s['to'] > $count) abort(422, 'ช่วงข้อของส่วนย่อยเกินจำนวนข้อใหม่ กรุณาแก้ส่วนย่อยด้วย');
        }
        return $d;
    }
}
