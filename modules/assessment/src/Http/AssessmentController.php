<?php

namespace Saan\Modules\Assessment\Http;

use App\Support\Saan\Idempotency;
use Illuminate\Http\Request;
use Saan\Modules\Assessment\Assessment;

class AssessmentController
{
    public function __construct(private Assessment $a) {}

    public function terms() { return response()->json(['data' => $this->a->terms()]); }

    public function lookups() { return response()->json($this->a->lookups()); }

    // ---- subjects ----
    public function subjects(Request $r) { return response()->json(['data' => $this->a->subjects($r->boolean('active'))]); }

    private function subjectRules(): array
    {
        return [
            'code' => 'required|string|max:20', 'name' => 'required|string|max:150', 'group_name' => 'nullable|string|max:100',
            'credit' => 'nullable|numeric|min:0|max:99.9', 'hours_per_year' => 'nullable|integer|min:0|max:2000',
            'grade_levels' => 'nullable|array|max:20', 'grade_levels.*' => 'string|max:20', 'is_active' => 'nullable|boolean',
        ];
    }

    public function subjectCreate(Request $r)
    {
        return response()->json($this->a->subjectSave($r->validate($this->subjectRules()), null, null), 201);
    }

    public function subjectUpdate(Request $r, int $id)
    {
        $d = $r->validate($this->subjectRules() + ['version' => 'required|integer|min:1']);
        return response()->json($this->a->subjectSave($d, $id, (int) $d['version']));
    }

    public function subjectDelete(Request $r, int $id)
    {
        $this->a->subjectDelete($id, (int) $r->validate(['version' => 'required|integer|min:1'])['version']);
        return response()->json(['ok' => true]);
    }

    // ---- courses ----
    /** Current term only (OPS-001). A term_id in the query string is ignored here on purpose. */
    public function courses(Request $r)
    {
        [$data, $term] = $this->a->courses($this->a->term(), $r->only(['classroom_id', 'subject_id']));
        return response()->json(['term' => Assessment::termInfo($term), 'data' => $data]);
    }

    /** Past/other terms: the term must be chosen explicitly. */
    public function history(Request $r)
    {
        $d = $r->validate(['term_id' => 'required|integer|min:1']);
        [$data, $term] = $this->a->courses($this->a->term((int) $d['term_id']), $r->only(['classroom_id', 'subject_id']));
        return response()->json(['term' => Assessment::termInfo($term), 'data' => $data, 'history' => true]);
    }

    public function courseCreate(Request $r)
    {
        $d = $r->validate(['term_id' => 'nullable|integer|min:1', 'subject_id' => 'required|integer|min:1', 'classroom_id' => 'required|integer|min:1', 'teacher_id' => 'nullable|integer|min:1', 'policy' => 'nullable|array']);
        return response()->json($this->a->courseCreate($this->a->term($d['term_id'] ?? null), (int) $d['subject_id'], (int) $d['classroom_id'], $d['teacher_id'] ?? null, $d['policy'] ?? null), 201);
    }

    public function courseBulk(Request $r)
    {
        $d = $r->validate(['term_id' => 'nullable|integer|min:1', 'subject_id' => 'required|integer|min:1', 'grade_level' => 'required|string|max:20', 'teacher_id' => 'nullable|integer|min:1', 'policy' => 'nullable|array']);
        return response()->json($this->a->courseBulk($this->a->term($d['term_id'] ?? null), (int) $d['subject_id'], trim($d['grade_level']), $d['teacher_id'] ?? null, $d['policy'] ?? null), 201);
    }

    public function courseCopy(Request $r)
    {
        $d = $r->validate(['from_term_id' => 'required|integer|min:1', 'to_term_id' => 'nullable|integer|min:1']);
        return response()->json($this->a->courseCopy($this->a->term((int) $d['from_term_id']), $this->a->term($d['to_term_id'] ?? null)), 201);
    }

    public function courseUpdate(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'teacher_id' => 'nullable|integer|min:1', 'policy' => 'nullable|array', 'status' => 'nullable|in:open,closed']);
        $in = array_intersect_key($d, array_flip(['teacher_id', 'policy', 'status']));
        foreach (['teacher_id', 'policy', 'status'] as $k) if (!$r->has($k)) unset($in[$k]);
        return response()->json($this->a->courseUpdate($id, (int) $d['version'], $in));
    }

    public function courseDelete(Request $r, int $id)
    {
        $this->a->courseDelete($id, (int) $r->validate(['version' => 'required|integer|min:1'])['version']);
        return response()->json(['ok' => true]);
    }

    // ---- scores ----
    public function scores(int $id) { return response()->json($this->a->read($id)); }

    public function scoresSave(Request $r, int $id)
    {
        $d = $r->validate([
            'cells' => 'required|array|min:1|max:1200', 'cells.*' => 'array:student_id,component_key,score,version',
            'cells.*.student_id' => 'required|integer|min:1', 'cells.*.component_key' => 'required|string|max:40',
            // present: a cell without a score key is rejected; clearing a cell must say score = null on purpose
            'cells.*.score' => 'present|nullable', 'cells.*.version' => 'required|integer|min:0',
            'reason' => 'nullable|string|max:200',
        ]);
        foreach ($d['cells'] as $i => $c) if (is_array($c['score'] ?? null)) abort(422, 'คะแนนต้องเป็นตัวเลข');
        return Idempotency::run($r, fn () => response()->json($this->a->saveScores($id, array_values($d['cells']), $d['reason'] ?? null)));
    }

    public function revisions(Request $r, int $id)
    {
        $d = $r->validate(['student_id' => 'nullable|integer|min:1', 'component_key' => 'nullable|string|max:40']);
        return response()->json(['data' => $this->a->revisions($id, $d['student_id'] ?? null, $d['component_key'] ?? null)]);
    }

    // ---- attributes ----
    public function attributes(Request $r, int $id)
    {
        $d = $r->validate(['kind' => 'nullable|in:'.implode(',', Assessment::KINDS)]);
        return response()->json(['data' => $this->a->attributes($id, $d['kind'] ?? null)]);
    }

    public function attributesSave(Request $r, int $id)
    {
        $d = $r->validate([
            'cells' => 'required|array|min:1|max:1200', 'cells.*' => 'array:student_id,kind,item_code,level,version',
            'cells.*.student_id' => 'required|integer|min:1', 'cells.*.kind' => 'required|in:'.implode(',', Assessment::KINDS),
            'cells.*.item_code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'], 'cells.*.level' => 'required|integer|between:0,3', 'cells.*.version' => 'required|integer|min:0',
        ]);
        return Idempotency::run($r, fn () => response()->json($this->a->saveAttributes($id, array_values($d['cells']))));
    }

    /** Removes mistaken attribute cells (version-checked, audited); only while the course is open and in the current term. */
    public function attributesDelete(Request $r, int $id)
    {
        $d = $r->validate($this->deleteRules());
        return Idempotency::run($r, fn () => response()->json($this->a->deleteCourseAttributes($id, array_values($d['cells']))));
    }

    public function termAttributesDelete(Request $r)
    {
        $d = $r->validate(['classroom_id' => 'required|integer|min:1'] + $this->deleteRules());
        return Idempotency::run($r, fn () => response()->json($this->a->deleteTermAttributes((int) $d['classroom_id'], array_values($d['cells']))));
    }

    private function deleteRules(): array
    {
        return ['cells' => 'required|array|min:1|max:200', 'cells.*' => 'array:student_id,kind,item_code,version', 'cells.*.student_id' => 'required|integer|min:1',
            'cells.*.kind' => 'required|in:'.implode(',', Assessment::KINDS), 'cells.*.item_code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'], 'cells.*.version' => 'required|integer|min:1'];
    }

    // ---- term-level attributes (homeroom teacher, current term) ----
    public function termAttributes(Request $r)
    {
        $d = $r->validate(['classroom_id' => 'required|integer|min:1', 'kind' => 'nullable|in:'.implode(',', Assessment::KINDS)]);
        return response()->json($this->a->termAttributes((int) $d['classroom_id'], $d['kind'] ?? null));
    }

    public function termAttributesSave(Request $r)
    {
        $d = $r->validate([
            'classroom_id' => 'required|integer|min:1',
            'cells' => 'required|array|min:1|max:1200', 'cells.*' => 'array:student_id,kind,item_code,level,version',
            'cells.*.student_id' => 'required|integer|min:1', 'cells.*.kind' => 'required|in:'.implode(',', Assessment::KINDS),
            'cells.*.item_code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'], 'cells.*.level' => 'required|integer|between:0,3', 'cells.*.version' => 'required|integer|min:0',
        ]);
        return Idempotency::run($r, fn () => response()->json($this->a->saveTermAttributes((int) $d['classroom_id'], array_values($d['cells']))));
    }
}
