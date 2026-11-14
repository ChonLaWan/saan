<?php

namespace Saan\Modules\LearningLinks\Http;

use App\Support\Saan\Idempotency;
use Illuminate\Http\Request;
use Saan\Modules\LearningLinks\Links;

/** /api/learning/*: staff side (admin, principal, teacher). Row-level scope is enforced in Saan\Modules\LearningLinks\Links. */
class LinkController
{
    private const MSG = [
        'title.required' => 'กรุณากรอกชื่อลิงก์', 'url.required' => 'กรุณากรอกลิงก์', 'version.required' => 'ไม่พบเลขเวอร์ชันของข้อมูล กรุณาโหลดใหม่',
        'days.min' => 'อายุรหัสต้องอย่างน้อย 1 วัน', 'days.max' => 'อายุรหัสต้องไม่เกิน 365 วัน',
    ];

    public function options(Request $r)
    {
        return response()->json(Links::options($r->user()));
    }

    public function index(Request $r)
    {
        $f = $r->validate(['classroom_id' => 'nullable|integer|min:1', 'course_id' => 'nullable|integer|min:1', 'all_terms' => 'nullable|boolean']);
        return response()->json(['data' => Links::list($r->user(), $f)]);
    }

    private function rules(bool $create): array
    {
        return ['title' => 'required|string|max:160', 'url' => 'required|string|max:600', 'kind' => 'nullable|string|max:10', 'api_client_id' => 'nullable|integer|min:1', 'sort' => 'nullable|integer|min:0|max:9999']
            + ($create ? ['classroom_id' => 'nullable|integer|min:1', 'course_id' => 'nullable|integer|min:1', 'term_id' => 'nullable|integer|min:1', 'all_terms' => 'nullable|boolean'] : ['version' => 'required|integer|min:1']);
    }

    public function store(Request $r)
    {
        $d = $r->validate($this->rules(true), self::MSG);
        return Idempotency::run($r, fn () => response()->json(Links::create($d, $r->user()), 201));
    }

    public function update(Request $r, int $id)
    {
        $d = $r->validate($this->rules(false), self::MSG);
        return response()->json(Links::update($id, $d, $r->user()));
    }

    public function destroy(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1'], self::MSG);
        Links::delete($id, (int) $d['version'], $r->user());
        return response()->noContent();
    }

    public function accessShow(Request $r, int $id)
    {
        if (!\App\Support\Saan\TeacherScope::canClassroom($id, $r->user())) abort(403, 'ไม่มีสิทธิ์ดูห้องเรียนนี้');
        return response()->json(Links::accessStatus($id) + ['page_path' => '/learn/'.$id]);
    }

    /** The plaintext code is in this response only; the database keeps an HMAC. */
    public function accessIssue(Request $r, int $id)
    {
        $d = $r->validate(['days' => 'nullable|integer|min:1|max:365'], self::MSG);
        $out = Links::issueCode($id, (int) ($d['days'] ?? 180), $r->user());
        return response()->json($out + ['page_path' => '/learn/'.$id], 201)->header('Cache-Control', 'no-store');
    }

    public function accessRevoke(Request $r, int $id)
    {
        Links::revokeCode($id, $r->user());
        return response()->noContent();
    }
}
