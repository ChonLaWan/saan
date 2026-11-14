<?php

namespace Saan\Modules\Timetable\Http;

use App\Support\Saan\Idempotency;
use Illuminate\Http\Request;
use Saan\Modules\Timetable\Timetable;

class TimetableController
{
    public function __construct(private Timetable $t) {}

    private const LESSON = [
        'term_id' => 'required|integer|min:1', 'teacher_id' => 'required|integer|min:1', 'classroom_id' => 'required|integer|min:1', 'period_id' => 'required|integer|min:1',
        'weekday' => 'required|integer|between:1,7', 'room' => 'nullable|string|max:100', 'subject' => 'required|string|max:120', 'note' => 'nullable|string|max:300', 'confirm' => 'nullable|boolean',
    ];

    public function meta(Request $r)
    {
        $d = $r->validate(['term_id' => 'nullable|integer|min:1']);
        return response()->json($this->t->meta(isset($d['term_id']) ? (int) $d['term_id'] : null));
    }

    public function lessons(Request $r)
    {
        $d = $r->validate(['term_id' => 'required|integer|min:1', 'view' => 'required|in:classroom,teacher,room', 'id' => 'nullable|integer|min:1', 'room' => 'nullable|string|max:100']);
        return response()->json($this->t->lessons((int) $d['term_id'], $d['view'], isset($d['id']) ? (int) $d['id'] : null, $d['room'] ?? null));
    }

    public function load(Request $r)
    {
        $d = $r->validate(['term_id' => 'required|integer|min:1']);
        return response()->json($this->t->load((int) $d['term_id']));
    }

    public function trash(Request $r)
    {
        $d = $r->validate(['term_id' => 'required|integer|min:1']);
        return response()->json($this->t->trash((int) $d['term_id']));
    }

    public function create(Request $r)
    {
        $d = $r->validate(self::LESSON);
        return Idempotency::run($r, fn () => response()->json($this->t->create($d, $r), 201));
    }

    public function update(Request $r, int $id)
    {
        $d = $r->validate(self::LESSON + ['version' => 'required|integer|min:1']);
        return response()->json($this->t->update($id, $d, $r));
    }

    public function destroy(Request $r, int $id)
    {
        $d = $r->validate(['term_id' => 'required|integer|min:1', 'version' => 'required|integer|min:1', 'confirm' => 'nullable|boolean']);
        return response()->json($this->t->delete($id, (int) $d['term_id'], (int) $d['version'], $r));
    }

    public function restore(Request $r, int $id)
    {
        $d = $r->validate(['term_id' => 'required|integer|min:1', 'version' => 'required|integer|min:1', 'confirm' => 'nullable|boolean']);
        return Idempotency::run($r, fn () => response()->json($this->t->restore($id, (int) $d['term_id'], (int) $d['version'], $r)));
    }

    private const COPY = ['from_term_id' => 'required|integer|min:1', 'to_term_id' => 'required|integer|min:1', 'classroom_ids' => 'nullable|array|max:200', 'classroom_ids.*' => 'integer|min:1'];

    public function copyPreview(Request $r)
    {
        return response()->json($this->t->copyPreview($r->validate(self::COPY)));
    }

    public function copyCommit(Request $r)
    {
        $d = $r->validate(self::COPY + ['skip_collisions' => 'nullable|boolean', 'confirm' => 'nullable|boolean']);
        return Idempotency::run($r, fn () => response()->json($this->t->copyCommit($d, $r), 201));
    }

    public function print(Request $r, string $type, int $id)
    {
        $d = $r->validate(['term' => 'nullable|integer|min:1']);
        return view('timetable::print', $this->t->printData($type, $id, isset($d['term']) ? (int) $d['term'] : null));
    }
}
