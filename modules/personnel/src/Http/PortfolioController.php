<?php

namespace Saan\Modules\Personnel\Http;

use App\Support\Saan\Idempotency;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Saan\Modules\Personnel\Files;
use Saan\Modules\Personnel\Folders;
use Saan\Modules\Personnel\Support;

/** Portfolio endpoints for teachers (own files) and reviewers (all teachers). */
class PortfolioController
{
    public function me(Request $r)
    {
        $own = Files::ownTeacherId();
        $teacherId = Support::isReviewer() ? ($r->query('teacher_id') ? (int) $r->query('teacher_id') : $own) : $own;
        if ($teacherId) Support::teacher($teacherId);
        $t = $teacherId ? Support::teacher($teacherId) : null;
        return response()->json([
            'teacher' => $t ? ['id' => (int) $t->id, 'name' => Support::teacherName($t)] : null,
            'own' => $teacherId !== null && $teacherId === $own,
            'is_reviewer' => Support::isReviewer(),
            'folders' => Folders::all(), 'usage' => $teacherId ? Files::usage($teacherId) : null, 'limits' => Files::limits(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function folders() { return response()->json(['data' => Folders::all()]); }

    public function index(Request $r)
    {
        $f = $r->validate(['teacher_id' => 'nullable|integer|min:1', 'folder_id' => 'nullable|integer|min:1', 'year' => 'nullable|integer|min:2500|max:2700',
            'status' => ['nullable', Rule::in(['active', 'archived', 'deleted'])], 'review_status' => ['nullable', Rule::in(Files::REVIEW)],
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json(Files::list($f))->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $r)
    {
        return Idempotency::run($r, fn () => response()->json(Files::upload($r), 201));
    }

    public function download(int $id) { return Files::download($id); }

    public function destroy(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        Files::delete($id, (int) $d['version']);
        return response()->json(['deleted' => true]);
    }

    public function review(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'review_status' => ['required', Rule::in(Files::REVIEW)], 'review_note' => 'nullable|string|max:1000']);
        return response()->json(Files::review($id, (int) $d['version'], $d['review_status'], $d['review_note'] ?? null));
    }

    public function zip(Request $r, int $teacherId)
    {
        $d = $r->validate(['year' => 'required|integer|between:'.Files::YEAR_MIN.','.Files::YEAR_MAX, 'folder_id' => 'nullable|integer|min:1']);
        return Files::zipResponse($teacherId, (int) $d['year'], isset($d['folder_id']) ? (int) $d['folder_id'] : null);
    }
}
