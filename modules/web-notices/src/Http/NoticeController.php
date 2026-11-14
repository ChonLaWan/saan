<?php

namespace Saan\Modules\WebNotices\Http;

use App\Support\Saan\Idempotency;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Saan\Modules\WebNotices\NameMask;
use Saan\Modules\WebNotices\NoticeRepository;
use Saan\Modules\WebNotices\RowParser;

/** Admin API. Routes are wrapped in auth + CurrentSchoolAccount + role:admin,principal,editor. */
class NoticeController
{
    public function __construct(private NoticeRepository $repo) {}

    public function index() { return response()->json(['data' => $this->repo->lists(), 'max_days' => NoticeRepository::MAX_DAYS, 'today' => NoticeRepository::today(), 'turnstile' => \App\Support\Saan\Turnstile::enabled(), 'full_default_days' => NoticeRepository::FULL_DEFAULT_DAYS]); }

    public function read(int $id) { return response()->json($this->repo->read($id)); }

    public function create(Request $r)
    {
        $d = $r->validate($this->rules(false));
        return Idempotency::run($r, fn () => response()->json($this->repo->create($d), 201));
    }

    public function update(Request $r, int $id)
    {
        $d = $r->validate($this->rules(true));
        return response()->json($this->repo->update($id, $d));
    }

    public function rows(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'text' => 'required|string|max:400000']);
        return response()->json($this->repo->replaceRows($id, (int) $d['version'], $d['text']));
    }

    public function status(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'action' => 'required|in:publish,archive,restore']);
        return response()->json($this->repo->setStatus($id, (int) $d['version'], $d['action']));
    }

    public function destroy(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        $this->repo->delete($id, (int) $d['version']);
        return response()->json(['ok' => true]);
    }

    private function rules(bool $update): array
    {
        $date = 'date_format:Y-m-d';
        return [
            'version' => $update ? 'required|integer|min:1' : 'prohibited',
            'title' => ($update ? 'sometimes|' : '').'required|string|max:200',
            'intro' => 'nullable|string|max:2000',
            'slug' => ['nullable', 'string', 'regex:/^[a-z0-9](?:[a-z0-9-]{1,78})[a-z0-9]$/D'],
            'columns' => 'sometimes|array|min:1|max:6', 'columns.*' => ['string', Rule::in(RowParser::ALLOWED)],
            'name_mode' => ['sometimes', Rule::in(NameMask::MODES)],
            // Asia/Bangkok local date-time ('Y-m-d H:i[:s]' or the datetime-local form with T); null/empty = default (publish_from + 14 days). Only with name_mode full.
            'full_name_until' => ['sometimes', 'nullable', 'string', 'max:25', function (string $attr, mixed $value, \Closure $fail) {
                if ($value !== null && $value !== '' && NoticeRepository::normUntil($value) === null) $fail('รูปแบบวันเวลาไม่ถูกต้อง');
            }],
            'search_only' => 'sometimes|boolean',
            'publish_from' => ($update ? 'sometimes|' : '').'required|'.$date,
            'expires_on' => ($update ? 'sometimes|' : '').'required|'.$date,
            // 6-7 digit numeric codes are only 10^6-10^7 guesses: require 8+ characters unless the code mixes in non-digits.
            'access_code' => ['nullable', 'string', 'min:6', 'max:40', function (string $attr, mixed $value, \Closure $fail) {
                if (is_string($value) && preg_match('/^\d+$/D', $value) === 1 && strlen($value) < 8) $fail('รหัสที่เป็นตัวเลขล้วนต้องยาวอย่างน้อย 8 หลัก หรือผสมตัวอักษร');
            }], 'clear_access_code' => 'sometimes|boolean',
        ];
    }
}
