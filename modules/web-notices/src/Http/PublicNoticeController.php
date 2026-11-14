<?php

namespace Saan\Modules\WebNotices\Http;

use App\Http\Controllers\SchoolWebsiteController;
use App\Support\Saan\NetKey;
use App\Support\Saan\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Saan\Modules\WebNotices\NoticeGate;
use Saan\Modules\WebNotices\NoticeRepository;
use Saan\Modules\WebNotices\NoticeSearch;

class PublicNoticeController
{
    /** Wrong codes for the whole list per hour (all networks together); a single network is stopped much earlier by NET_FAILS_PER_HOUR. */
    public const LIST_FAILS_PER_HOUR = 1000;
    public const NET_FAILS_PER_HOUR = 30;
    public const REFUSED = 'ไม่สามารถแสดงรายชื่อได้ในขณะนี้ กรุณาลองใหม่อีกครั้งภายหลัง';
    private const LABELS = ['order_no' => 'ลำดับ', 'display_name' => 'ชื่อ', 'classroom' => 'ห้อง/ชั้น', 'group' => 'กลุ่ม', 'note' => 'หมายเหตุ', 'applicant_no' => 'เลขที่สมัคร/เลขที่นั่งสอบ'];

    public function __construct(private NoticeRepository $repo) {}

    /** Hidden, expired, archived, draft or unknown: all the same 404 (never 410) so existence is not confirmed. */
    public function show(Request $r, string $slug)
    {
        $l = $this->live($slug);
        if ($l->access_code_hash && !$this->unlocked($r, $l)) return $this->page($l, null, null);
        // Full-name and search-only lists: the HTML carries no name and no row at all, only title, intro, count and the Turnstile step.
        if ($this->repo->gated($l)) return $this->gatePage($l);
        return $this->page($l, $this->repo->publicRows($l), null);
    }

    public function unlock(Request $r, string $slug)
    {
        $l = $this->live($slug);
        if (!$l->access_code_hash) return redirect('/portal/notices/'.$l->slug);
        // Only wrong codes count. Two layers: per network (IPv6 as /64) + slug, and a list-wide ceiling that does not depend on the
        // caller's address, so a botnet or a rotating /64 cannot walk a 6-digit code space. When the list-wide budget is spent the
        // form is closed for everyone (even the right code) until the hour window decays.
        $key = 'notice-unlock:'.NetKey::rate($r->ip()).'|'.$l->slug;
        // failures per network and hour (/64 and /48): one client spends its own share long before it can reach the list-wide ceiling that closes the form for everybody
        $netHour = 'notice-unlock-net:'.NetKey::rate($r->ip()).'|'.$l->id;
        $wideHour = 'notice-unlock-wide:'.NetKey::wide($r->ip()).'|'.$l->id;
        $listKey = 'notice-unlock-list:'.$l->id;
        if (RateLimiter::tooManyAttempts($listKey, self::LIST_FAILS_PER_HOUR)) return $this->page($l, null, 'มีการลองรหัสผิดจำนวนมาก ระบบปิดการกรอกรหัสชั่วคราว กรุณาลองใหม่ภายหลังหรือแจ้งเจ้าหน้าที่')->setStatusCode(429);
        if (RateLimiter::tooManyAttempts($key, 10) || RateLimiter::tooManyAttempts($netHour, self::NET_FAILS_PER_HOUR) || RateLimiter::tooManyAttempts($wideHour, self::NET_FAILS_PER_HOUR * 2)) return $this->page($l, null, 'ลองรหัสผิดหลายครั้ง กรุณารอสักครู่แล้วลองใหม่')->setStatusCode(429);
        $code = $r->input('code');
        if (!is_string($code) || $code === '' || strlen($code) > 100 || !NoticeRepository::checkCode($l->access_code_hash, $code)) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($netHour, 3600);
            RateLimiter::hit($wideHour, 3600);
            RateLimiter::hit($listKey, 3600);
            return $this->page($l, null, 'รหัสเข้าดูไม่ถูกต้อง')->setStatusCode(422);
        }
        $r->session()->regenerate();
        $r->session()->put('notice_unlock.'.$l->id, NoticeRepository::unlockToken($l));
        return redirect('/portal/notices/'.$l->slug);
    }

    // ------------------------------------------------------------------ anti-bot delivery (full-name and search-only lists)

    /** Step 1: verify Turnstile on the server and hand out a one-time token (valid 2 minutes, this session and this list only). */
    public function challenge(Request $r, string $slug): JsonResponse
    {
        $l = $this->live($slug);
        if (NoticeGate::limited($r, $l)) return $this->refuse(429);
        if (!$this->deliverable($r, $l)) return $this->refuse();
        if (!Turnstile::strict($r->input('cf-turnstile-response'))) return $this->refuse();
        if (NoticeGate::listLimited($l)) return $this->refuse(429);
        return $this->json(['token' => NoticeGate::issue($r, $l), 'expires_in' => NoticeGate::ttl()]);
    }

    /** Step 2 (full list): the token returns the rows as JSON. Refused for search-only lists. */
    public function rows(Request $r, string $slug): JsonResponse
    {
        $l = $this->live($slug);
        if (NoticeGate::limited($r, $l)) return $this->refuse(429);
        if (!$this->deliverable($r, $l) || $this->repo->searchOnly($l) || !$this->repo->fullActive($l)) return $this->refuse();
        if (!NoticeGate::consume($r, $l, $r->input('token'))) return $this->refuse();
        if (NoticeGate::listLimited($l)) return $this->refuse(429);
        return $this->json(['columns' => $this->columns($l), 'rows' => $this->repo->publicRows($l, true), 'full' => $this->repo->fullShown($l)]);
    }

    /** Step 2 (search-only list): the token answers one query with at most 5 matching rows, never the whole list. */
    public function search(Request $r, string $slug): JsonResponse
    {
        $l = $this->live($slug);
        if (NoticeGate::limited($r, $l)) return $this->refuse(429);
        if (!$this->deliverable($r, $l) || !$this->repo->searchOnly($l)) return $this->refuse();
        if (NoticeGate::searchLimited($r, $l)) return $this->refuse(429);
        $numeric = $this->repo->hasApplicantNo($l);
        $q = NoticeSearch::parse($r->input('q'), $numeric);
        if ($q === null) return $this->json(['message' => 'พิมพ์ชื่ออย่างน้อย '.NoticeSearch::MIN_CHARS.' ตัวอักษร (ไม่นับคำนำหน้าเพียงอย่างเดียว)'.($numeric ? ' หรือเลขที่สมัคร/เลขที่นั่งสอบ (อย่างน้อย '.NoticeSearch::MIN_DIGITS.' หลัก) ตามด้วยชื่ออย่างน้อย '.NoticeSearch::MIN_CHARS.' ตัวอักษร' : '')], 422);
        if (!NoticeGate::consume($r, $l, $r->input('token'))) return $this->refuse();
        if (NoticeGate::listLimited($l)) return $this->refuse(429);
        $hit = $this->repo->searchRows($l, $q);
        return $this->json(['columns' => $this->columns($l), 'rows' => $hit['rows'], 'more' => $hit['more'], 'full' => $this->repo->fullShown($l)]);
    }

    /** Gated list, Turnstile configured, and the access code (if any) already accepted in this session. */
    private function deliverable(Request $r, object $l): bool
    {
        if (!$this->repo->gated($l) || !Turnstile::enabled()) return false;
        return !$l->access_code_hash || $this->unlocked($r, $l);
    }

    /** @return list<array{key:string,label:string}> */
    private function columns(object $l): array
    {
        return array_map(fn ($c) => ['key' => $c, 'label' => self::LABELS[$c] ?? $c], array_values(array_filter(json_decode((string) $l->columns, true) ?: [], fn ($c) => isset(self::LABELS[$c]))));
    }

    /** One generic answer for every refusal (bad or reused token, Turnstile failure, wrong session, closed list), so nothing can be probed. */
    private function refuse(int $status = 403): JsonResponse
    {
        return $this->json(['message' => self::REFUSED], $status);
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store, no-cache, private', 'Pragma' => 'no-cache', 'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Referrer-Policy' => 'no-referrer']);
    }

    private function live(string $slug): object
    {
        return (preg_match('/^[a-z0-9-]{3,80}$/D', $slug) ? $this->repo->publicList($slug) : null) ?? abort(404);
    }

    private function unlocked(Request $r, object $l): bool
    {
        $t = $r->session()->get('notice_unlock.'.$l->id);
        return is_string($t) && hash_equals(NoticeRepository::unlockToken($l), $t);
    }

    private function context(object $l): array
    {
        return array_replace(app(SchoolWebsiteController::class)->context(), [
            'pageTitle' => $l->title, 'list' => (object) ['slug' => $l->slug, 'title' => $l->title, 'intro' => $l->intro, 'expires_on' => $l->expires_on], 'columns' => json_decode((string) $l->columns, true) ?: [],
            'rows' => null, 'error' => null, 'gate' => null,
        ]);
    }

    private function gatePage(object $l)
    {
        $data = $this->context($l);
        $full = $this->repo->fullShown($l);
        $data['gate'] = ['ready' => Turnstile::enabled(), 'siteKey' => Turnstile::siteKey(), 'search' => $this->repo->searchOnly($l), 'count' => $this->repo->rowCount($l),
            'full' => $full, 'until' => $full ? $this->repo->untilOf($l) : null, 'min' => NoticeSearch::MIN_CHARS, 'max' => NoticeSearch::MAX_RESULTS,
            'numeric' => $this->repo->hasApplicantNo($l), 'min_digits' => NoticeSearch::MIN_DIGITS];
        return response(view('web-notices::public', $data)->render())->withHeaders(['Cache-Control' => 'no-store, no-cache, private', 'Referrer-Policy' => 'no-referrer']);
    }

    private function page(object $l, ?array $rows, ?string $error)
    {
        $data = $this->context($l);
        $data['rows'] = $rows;
        $data['error'] = $error;
        return response(view('web-notices::public', $data)->render());
    }
}
