<?php

namespace Saan\Modules\Constructionism\Http;

use App\Support\Saan\Idempotency;
use App\Support\Saan\Mutation;
use Illuminate\Http\Request;
use Saan\Modules\Constructionism\Files;
use Saan\Modules\Constructionism\Pbl;
use Saan\Modules\Constructionism\Reflections;
use Saan\Modules\Constructionism\Rubric;

/** /api/pbl/* and the printable summary. Role middleware is on the routes; row scope is enforced in Pbl. */
class ProjectController
{
    private const MSG = [
        'title.required' => 'กรุณากรอกชื่อ', 'version.required' => 'ไม่พบเลขเวอร์ชันของข้อมูล กรุณาโหลดใหม่', 'classroom_id.required' => 'กรุณาเลือกห้องเรียน',
        'student_id.required' => 'กรุณาเลือกนักเรียน', 'date.required' => 'กรุณาเลือกวันที่', 'date.date_format' => 'วันที่ไม่ถูกต้อง', 'entry.required' => 'กรุณากรอกบันทึก',
        'file.max' => 'ไฟล์ใหญ่เกินกำหนด',
    ];

    private function v(Request $r, array $rules): array
    {
        return $r->validate($rules, self::MSG);
    }

    private function ver(Request $r): int
    {
        return (int) $this->v($r, ['version' => 'required|integer|min:1'])['version'];
    }

    public function options(Request $r)
    {
        return response()->json(Pbl::options($r->user()));
    }

    public function index(Request $r)
    {
        $f = $this->v($r, ['term_id' => 'nullable|integer|min:1', 'classroom_id' => 'nullable|integer|min:1', 'status' => 'nullable|string|max:12']);
        return response()->json(['data' => Pbl::list($r->user(), $f)]);
    }

    public function show(Request $r, int $id)
    {
        return response()->json(Pbl::detail(Pbl::readable($id, $r->user()), $r->user()));
    }

    private const PROJECT = ['title' => 'required|string|max:200', 'driving_question' => 'nullable|string|max:1500', 'advisor_teacher_id' => 'nullable|integer|min:1'];

    public function store(Request $r)
    {
        $d = $this->v($r, self::PROJECT + ['classroom_id' => 'required|integer|min:1']);
        return Idempotency::run($r, fn () => response()->json(Pbl::create($d, $r->user()), 201));
    }

    public function update(Request $r, int $id)
    {
        $d = $this->v($r, self::PROJECT + ['status' => 'required|string|max:12', 'version' => 'required|integer|min:1']);
        return response()->json(Pbl::update($id, $d, $r->user()));
    }

    public function destroy(Request $r, int $id)
    {
        Pbl::delete($id, $this->ver($r), $r->user());
        return response()->noContent();
    }

    // members
    public function memberStore(Request $r, int $id)
    {
        $d = $this->v($r, ['student_id' => 'required|integer|min:1', 'role' => 'nullable|string|max:80']);
        return response()->json(Pbl::addMember($id, $d, $r->user()), 201);
    }

    public function memberUpdate(Request $r, int $id)
    {
        return response()->json(Pbl::updateMember($id, $this->v($r, ['role' => 'nullable|string|max:80', 'version' => 'required|integer|min:1']), $r->user()));
    }

    public function memberDestroy(Request $r, int $id)
    {
        return response()->json(Pbl::removeMember($id, $this->ver($r), $r->user()));
    }

    // journal
    private const LOG = ['date' => 'required|date_format:Y-m-d', 'entry' => 'required|string|max:5000', 'by_teacher' => 'nullable|boolean'];

    public function logStore(Request $r, int $id)
    {
        $d = $this->v($r, self::LOG);
        return Idempotency::run($r, fn () => response()->json(Pbl::addLog($id, $d, $r->user()), 201));
    }

    public function logUpdate(Request $r, int $id)
    {
        return response()->json(Pbl::updateLog($id, $this->v($r, self::LOG + ['version' => 'required|integer|min:1']), $r->user()));
    }

    public function logDestroy(Request $r, int $id)
    {
        return response()->json(Pbl::removeLog($id, $this->ver($r), $r->user()));
    }

    // artifacts
    public function artifactStore(Request $r, int $id)
    {
        $d = $this->v($r, ['title' => 'required|string|max:200', 'kind' => 'nullable|string|max:10', 'url' => 'nullable|string|max:600', 'file' => 'nullable|file']);
        return Idempotency::run($r, fn () => response()->json(Files::add($id, $d, $r->file('file'), $r->user()), 201));
    }

    public function artifactUpdate(Request $r, int $id)
    {
        return response()->json(Files::update($id, $this->v($r, ['title' => 'required|string|max:200', 'kind' => 'nullable|string|max:10', 'url' => 'nullable|string|max:600', 'version' => 'required|integer|min:1']), $r->user()));
    }

    public function artifactDestroy(Request $r, int $id)
    {
        return response()->json(Files::remove($id, $this->ver($r), $r->user()));
    }

    public function artifactFile(Request $r, int $id)
    {
        return Files::download($id, $r->user());
    }

    // reflections
    private const REFL = ['text' => 'nullable|string|max:5000', 'rubric' => 'nullable|array'];

    public function reflectionStore(Request $r, int $id)
    {
        return response()->json(Reflections::add($id, $this->v($r, self::REFL + ['student_id' => 'required|integer|min:1']), $r->user()), 201);
    }

    public function reflectionUpdate(Request $r, int $id)
    {
        return response()->json(Reflections::update($id, $this->v($r, self::REFL + ['version' => 'required|integer|min:1']), $r->user()));
    }

    public function reflectionDestroy(Request $r, int $id)
    {
        return response()->json(Reflections::remove($id, $this->ver($r), $r->user()));
    }

    // rubric (school setting)
    public function rubric()
    {
        return response()->json(['criteria' => Rubric::criteria(), 'revision' => Rubric::revision(), 'custom' => Rubric::isCustom(), 'levels' => Rubric::LEVELS]);
    }

    public function rubricSave(Request $r)
    {
        $d = $this->v($r, ['criteria' => 'required|array|min:1|max:10', 'criteria.*' => 'array', 'revision' => 'required|string|max:64']);
        $criteria = Rubric::save($d['criteria'], $d['revision']);
        Mutation::audit('pbl.rubric.update', 'saan_settings', null, ['criteria' => count($criteria)]);
        return response()->json(['criteria' => $criteria, 'revision' => Rubric::revision(), 'custom' => true, 'levels' => Rubric::LEVELS]);
    }

    // printable summary
    public function print(Request $r, int $id)
    {
        $p = Pbl::readable($id, $r->user());
        $d = Pbl::detail($p, $r->user());
        Mutation::audit('pbl.print', 'con_projects', $id);
        $term = \Illuminate\Support\Facades\DB::table('academic_terms')->where('id', $p->term_id)->first();
        return response()->view('constructionism::print', ['d' => $d, 'term' => $term, 'levels' => Rubric::LEVELS])->withHeaders(['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex']);
    }
}
