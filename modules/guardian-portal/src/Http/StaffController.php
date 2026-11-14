<?php

namespace Saan\Modules\GuardianPortal\Http;

use App\Support\Saan\Settings;
use App\Support\Saan\TeacherScope;
use App\Support\Saan\TermScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Saan\Modules\GuardianPortal\Access;
use Saan\Modules\GuardianPortal\Links;

/**
 * Staff side of the portal (work page #guardians). Admin/principal see every classroom, a teacher only the homeroom
 * (TeacherScope::homeroomIds). Staff JSON never carries guardian phone/e-mail, identifiers or pickup notes; the one-time
 * link URL appears only in the response that creates it.
 */
class StaffController
{
    private function user(): object
    {
        return auth()->user();
    }

    private function manager(): bool
    {
        return in_array($this->user()->role, ['admin', 'principal'], true);
    }

    public function context(): JsonResponse
    {
        $ids = TeacherScope::homeroomIds($this->user());
        $q = DB::table('classrooms')->where('school_id', Access::school())->orderBy('name');
        if ($ids !== null) $q->whereIn('id', $ids ?: [0]);
        return response()->json([
            'classrooms' => $q->get(['id', 'name'])->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->all(),
            'settings' => $this->settingsOut(),
            'can_manage_settings' => $this->manager(),
            'link_days' => ['default' => Links::DEFAULT_DAYS, 'max' => Links::MAX_DAYS],
        ]);
    }

    public function settings(Request $r): JsonResponse
    {
        $d = $r->validate(['restricted_can_view' => ['sometimes', 'boolean'], 'show_pp6' => ['sometimes', 'boolean'], 'password_min' => ['sometimes', 'integer', 'between:6,64']]);
        if (!$d) abort(422, 'ไม่มีค่าที่จะบันทึก');
        if (array_key_exists('restricted_can_view', $d)) Settings::put(Access::RESTRICTED_SETTING, (bool) $d['restricted_can_view']);
        if (array_key_exists('show_pp6', $d)) Settings::put(\Saan\Modules\GuardianPortal\Announce::SHOW_PP6, (bool) $d['show_pp6']);
        if (array_key_exists('password_min', $d)) Settings::put(\Saan\Modules\GuardianPortal\SecretPolicy::KEY, (int) $d['password_min']);
        \App\Support\Saan\Mutation::audit('guardian_portal.settings.changed', 'saan_settings', null, $d);
        return response()->json(['settings' => $this->settingsOut()]);
    }

    private function settingsOut(): array
    {
        return ['restricted_can_view' => Access::restrictedCanView(), 'show_pp6' => \Saan\Modules\GuardianPortal\Announce::showPp6(), 'password_min' => \Saan\Modules\GuardianPortal\SecretPolicy::min()];
    }

    /** F8: terms with their announcement state (any staff role may read; only admin/principal may change). */
    public function terms(): JsonResponse
    {
        return response()->json(['data' => \Saan\Modules\GuardianPortal\Announce::terms(), 'can_manage' => $this->manager()]);
    }

    /** mode now = announce immediately, schedule = at a future date-time (Y-m-d\TH:i, school time zone), clear = hide the term again. */
    public function announce(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['mode' => ['required', 'in:now,schedule,clear'], 'at' => ['required_if:mode,schedule', 'nullable', 'date_format:Y-m-d\TH:i']]);
        $at = match ($d['mode']) {
            'now' => now()->format('Y-m-d H:i:s'),
            'schedule' => \Carbon\Carbon::createFromFormat('Y-m-d\TH:i', $d['at'], config('app.timezone'))->format('Y-m-d H:i:s'),
            default => null,
        };
        if ($d['mode'] === 'schedule' && strtotime($at) <= time()) abort(422, 'เวลาที่ตั้งต้องอยู่ในอนาคต (ถ้าต้องการประกาศทันทีให้เลือก "ประกาศทันที")');
        \Saan\Modules\GuardianPortal\Announce::set($id, $at);
        return response()->json(['data' => \Saan\Modules\GuardianPortal\Announce::terms()]);
    }

    public function guardians(int $id): JsonResponse
    {
        $school = Access::school();
        $room = DB::table('classrooms')->where('school_id', $school)->where('id', $id)->first(['id', 'name']);
        if (!$room) abort(404);
        $ids = TeacherScope::homeroomIds($this->user());
        if ($ids !== null && !in_array($id, $ids, true)) abort(403, 'ดูได้เฉพาะห้องประจำชั้นของตนเอง');

        $sq = DB::table('students')->where('school_id', $school)->where('classroom_id', $id)->orderBy('id');
        if (Schema::hasColumn('students', 'status')) $sq->where('status', 'studying');
        $students = $sq->get(['id', 'name', 'first_name', 'last_name']);
        $sids = $students->pluck('id')->all();
        $allowRestricted = Access::restrictedCanView();

        $rels = $sids ? DB::table('reg_student_guardians as sg')->join('reg_guardians as g', 'g.id', '=', 'sg.guardian_id')
            ->where('sg.school_id', $school)->whereIn('sg.student_id', $sids)->orderByDesc('sg.is_primary')->orderBy('g.display_name')
            ->get(['sg.student_id', 'sg.guardian_id', 'sg.relationship', 'sg.is_primary', 'sg.can_pick_up', 'g.display_name'])->groupBy('student_id') : collect();
        $gids = $rels->flatten(1)->pluck('guardian_id')->unique()->all();

        $links = $sids ? DB::table('gp_links')->where('school_id', $school)->whereIn('student_id', $sids)->whereIn('guardian_id', $gids ?: [0])
            ->orderByDesc('id')->get(['id', 'student_id', 'guardian_id', 'purpose', 'expires_at', 'used_at', 'revoked_at', 'version']) : collect();
        $accounts = $gids ? DB::table('gp_accounts')->where('school_id', $school)->whereIn('guardian_id', $gids)->get(['id', 'version', 'guardian_id', 'is_active', 'last_login_at'])->keyBy('guardian_id') : collect();

        $now = now();
        $linkState = function (?object $l) use ($now) {
            if (!$l) return null;
            $status = $l->revoked_at ? 'revoked' : ($l->used_at ? 'used' : (strtotime((string) $l->expires_at) <= $now->getTimestamp() ? 'expired' : 'pending'));
            return ['id' => (int) $l->id, 'version' => (int) $l->version, 'status' => $status, 'expires_at' => (string) $l->expires_at, 'used_at' => $l->used_at ? (string) $l->used_at : null];
        };

        $termId = TermScope::currentId();
        $month = now('Asia/Bangkok')->format('Y-m');
        $bhv = collect();
        if ($sids && $termId && \Saan\Modules\GuardianPortal\GuardianData::moduleData('behavior', 'bhv_records')) {
            $bhv = DB::table('bhv_records')->where('school_id', $school)->where('term_id', $termId)->whereIn('student_id', $sids)->where('status', 'active')->where('guardian_visible', true)
                ->selectRaw('student_id, count(*) as total, sum(case when guardian_ack_at is null then 0 else 1 end) as acked')->groupBy('student_id')->get()->keyBy('student_id');
        }
        $acks = $sids ? DB::table('gp_acks')->where('school_id', $school)->whereIn('student_id', $sids)->get(['student_id', 'kind', 'ref_id', 'ref_month'])->groupBy('student_id') : collect();
        // latest guardian comments per student (shown escaped in the teacher page)
        $notes = $sids ? DB::table('gp_acks as a')->leftJoin('reg_guardians as g', 'g.id', '=', 'a.guardian_id')->where('a.school_id', $school)->whereIn('a.student_id', $sids)
            ->whereNotNull('a.note')->orderByDesc('a.id')->limit(300)->get(['a.student_id', 'a.kind', 'a.ref_month', 'a.note', 'a.acknowledged_at', 'g.display_name'])->groupBy('student_id') : collect();

        $rows = $students->map(function ($s) use ($rels, $links, $accounts, $linkState, $allowRestricted, $bhv, $acks, $notes, $month) {
            $full = trim(trim((string) $s->first_name).' '.trim((string) $s->last_name));
            $mine = $acks->get($s->id, collect());
            $b = $bhv->get($s->id);
            return [
                'student_id' => (int) $s->id, 'student_name' => $full !== '' ? $full : (string) $s->name,
                'acks' => [
                    'behavior_total' => $b ? (int) $b->total : 0, 'behavior_acked' => $b ? (int) $b->acked : 0,
                    'attendance_month_acked' => $mine->where('kind', 'attendance_month')->where('ref_month', $month)->isNotEmpty(),
                    'term_results_acked' => $mine->where('kind', 'term_result')->pluck('ref_id')->unique()->count(),
                ],
                'notes' => $notes->get($s->id, collect())->take(5)->map(fn ($n) => ['guardian' => $n->display_name, 'kind' => $n->kind, 'ref_month' => $n->ref_month,
                    'note' => $n->note, 'at' => (string) $n->acknowledged_at])->values()->all(),
                'guardians' => $rels->get($s->id, collect())->map(function ($g) use ($s, $links, $accounts, $linkState, $allowRestricted) {
                    $acc = $accounts->get($g->guardian_id);
                    $pair = fn ($l) => (int) $l->student_id === (int) $s->id && (int) $l->guardian_id === (int) $g->guardian_id;
                    // access belongs to the pair: any used, unrevoked invite means the guardian can still see the child, whichever row is newest
                    $own = fn (string $p) => ($p === 'invite' ? $links->first(fn ($l) => $pair($l) && $l->purpose === 'invite' && $l->used_at !== null && $l->revoked_at === null) : null)
                        ?? $links->first(fn ($l) => $pair($l) && $l->purpose === $p);
                    return [
                        'guardian_id' => (int) $g->guardian_id, 'name' => $g->display_name, 'relationship' => $g->relationship, 'is_primary' => (bool) $g->is_primary,
                        'may_view' => (bool) $g->can_pick_up || $allowRestricted,
                        'view_link' => $linkState($own('view')), 'invite_link' => $linkState($own('invite')),
                        'account' => $acc ? ['id' => (int) $acc->id, 'version' => (int) $acc->version, 'active' => (bool) $acc->is_active, 'last_login_at' => $acc->last_login_at ? (string) $acc->last_login_at : null] : null,
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        return response()->json(['classroom' => ['id' => (int) $room->id, 'name' => $room->name], 'students' => $rows]);
    }

    public function createLink(Request $r): JsonResponse
    {
        $d = $r->validate([
            'student_id' => ['required', 'integer', 'min:1'], 'guardian_id' => ['required', 'integer', 'min:1'],
            'purpose' => ['nullable', 'in:view,invite'], 'days' => ['nullable', 'integer', 'min:1', 'max:'.Links::MAX_DAYS],
        ]);
        $made = Links::create($this->user(), (int) $d['student_id'], (int) $d['guardian_id'], $d['purpose'] ?? 'view', isset($d['days']) ? (int) $d['days'] : null);
        $l = $made['link'];
        return response()->json([
            'id' => (int) $l->id, 'version' => (int) $l->version, 'purpose' => $l->purpose, 'expires_at' => (string) $l->expires_at,
            'url' => $made['url'], 'path' => parse_url($made['url'], PHP_URL_PATH),
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function disableAccount(Request $r, int $guardianId): JsonResponse
    {
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'active' => ['required', 'boolean']]);
        if ($r->boolean('active')) abort(422, 'ปิดบัญชีได้อย่างเดียว การเปิดใช้ใหม่ทำโดยออกลิงก์ตั้งบัญชี');
        $a = Links::disableAccount($this->user(), $guardianId, (int) $d['version']);
        return response()->json(['guardian_id' => (int) $a->guardian_id, 'version' => (int) $a->version, 'active' => (bool) $a->is_active]);
    }

    public function revokeLink(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['version' => ['required', 'integer', 'min:1']]);
        $l = Links::revoke($id, (int) $d['version']);
        return response()->json(['id' => (int) $l->id, 'version' => (int) $l->version, 'revoked' => true]);
    }
}
