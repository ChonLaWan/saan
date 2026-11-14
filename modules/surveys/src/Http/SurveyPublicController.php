<?php

namespace Saan\Modules\Surveys\Http;

use App\Http\Controllers\SchoolWebsiteController;
use App\Support\Saan\Edition;
use App\Support\Saan\HashKey;
use App\Support\Saan\Inbox;
use App\Support\Saan\Inbox\InboxRejected;
use App\Support\Saan\NetKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Saan\Modules\Surveys\SurveyRejected;
use Saan\Modules\Surveys\SurveyService;

class SurveyPublicController
{
    public const VERIFY_NOKEY_PER_MINUTE = 10;

    public function __construct(private SurveyService $svc) {}

    private function page(string $view, array $data, int $status = 200)
    {
        return response()->view($view, app(SchoolWebsiteController::class)->context() + $data, $status)->header('X-Robots-Tag', 'noindex');
    }

    private function notice(string $title, string $message, int $status, array $extra = [])
    {
        return $this->page('surveys::notice', ['pageTitle' => $title, 'heading' => $title, 'message' => $message] + $extra, $status);
    }

    /** Links to a certificate the respondent owns (the k token is what lets them open the full document). */
    private function certLinks(?string $no): array
    {
        return $no ? ['certificateNo' => $no, 'certUrl' => $this->svc->certUrl($no), 'verifyUrl' => $this->svc->verifyUrl($no)] : [];
    }

    private function reject(Request $r, SurveyRejected $e, ?object $survey = null)
    {
        if ($r->expectsJson()) {
            // never a certificate number or link on a refusal: only the successful insert hands those out
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->status);
        }
        return $this->notice($survey->title ?? 'แบบสอบถาม', $e->getMessage(), $e->status);
    }

    public function show(string $slug)
    {
        $survey = $this->svc->findBySlug($slug);
        try { $this->svc->rejectIfClosed($survey, true); }
        catch (SurveyRejected $e) { return $this->notice($survey->title, $e->getMessage(), $e->status); }
        return $this->page('surveys::public', ['pageTitle' => $survey->title, 'survey' => $survey, 'questions' => $this->svc->questions($survey), 'cert' => $this->svc->certificate($survey)]);
    }

    public function submit(Request $r, string $slug)
    {
        $survey = $this->svc->findBySlug($slug);
        $cookieName = 'srv_'.$survey->id;
        if (Edition::isPublic()) return $this->submitSealed($r, $survey, $cookieName);
        try {
            $result = $this->svc->submit($survey, $r->only(['a', 'name', 'email', 'code']), $r->ip(), $r->cookie($cookieName), $r->hasSession() ? $r->session()->getId() : '');
        } catch (SurveyRejected $e) {
            return $this->reject($r, $e, $survey);
        }
        $cert = $result['response']?->certificate_no;
        $payload = ['status' => $result['status'], 'certificate_no' => $cert, 'certificate_url' => $cert ? $this->svc->certUrl($cert) : null];
        $response = $r->expectsJson() ? response()->json($payload, 201) : $this->doneView($survey, $result['status'], $cert);
        if ($result['cookie']) $response->cookie($cookieName, $result['cookie'], 60 * 24 * 365, '/', null, $r->isSecure(), true, false, 'lax');
        return $response;
    }

    public const QUEUED_MESSAGE = 'รับคำตอบแล้ว จะตรวจสิทธิ์และยืนยันภายหลัง หากแบบสอบถามนี้ต้องยืนยันทางอีเมล ลิงก์ยืนยันจะถูกส่งถึงท่านภายในไม่กี่นาที (ตรวจกล่องจดหมายขยะด้วย) และคำตอบจะนับเมื่อยืนยันแล้วเท่านั้น';

    /**
     * S5, public edition: the answer is checked against the published survey, then sealed for the internal edition (Inbox kind "survey").
     * This side holds no response table, sends no mail and cannot say whether the person already answered: uniqueness, roster codes and the
     * verification mail are handled by the internal edition after it pulled the envelope.
     */
    private function submitSealed(Request $r, object $survey, string $cookieName)
    {
        try {
            $input = $this->svc->precheck($survey, $r->only(['a', 'name', 'email', 'code']));
            $budget = $survey->identity_mode === 'name_email' ? SurveyService::MAIL_PER_IP_HOUR : max(1, (int) ($survey->anon_ip_hour ?? SurveyService::ANON_PER_IP_HOUR));
            $limiter = 'srv-sealed:'.$survey->id.':'.NetKey::rate($r->ip());
            if (RateLimiter::tooManyAttempts($limiter, $budget)) throw new SurveyRejected(429, 'มีการส่งคำตอบจากเครือข่ายนี้จำนวนมาก กรุณาลองใหม่ภายหลัง', 'ip_limit');
            $cookie = $r->cookie($cookieName);
            $token = is_string($cookie) && preg_match('/^[a-f0-9]{32}$/D', $cookie) ? $cookie : bin2hex(random_bytes(16));
            try {
                Inbox::submit('survey', ['slug' => $survey->slug, 'input' => $input, 'ip' => $r->ip(), 'cookie' => $token, 'session' => $r->hasSession() ? hash_hmac('sha256', $r->session()->getId(), HashKey::bytes(HashKey::secret())) : '']); // never the real session id (a bearer credential)
            } catch (InboxRejected $e) {
                throw new SurveyRejected($e->status >= 500 ? 503 : 422, $e->getMessage(), 'inbox_'.$e->reason);
            }
            RateLimiter::hit($limiter, 3600);
        } catch (SurveyRejected $e) {
            return $this->reject($r, $e, $survey);
        }
        $response = $r->expectsJson() ? response()->json(['status' => 'queued', 'message' => self::QUEUED_MESSAGE], 202)
            : $this->notice('รับคำตอบแล้ว', self::QUEUED_MESSAGE, 202, ['ok' => true]);
        if ($survey->identity_mode === 'anonymous') $response->cookie($cookieName, $token, 60 * 24 * 365, '/', null, $r->isSecure(), true, false, 'lax');
        return $response;
    }

    private function doneView(object $survey, string $status, ?string $cert, string $message = 'ระบบบันทึกคำตอบของท่านแล้ว')
    {
        if ($status === 'pending') return $this->notice('ตรวจสอบอีเมลของท่าน', 'เราส่งลิงก์ยืนยันไปทางอีเมลแล้ว กรุณากดลิงก์ภายใน 24 ชั่วโมงแล้วกดปุ่มยืนยัน คำตอบจะนับเมื่อยืนยันแล้วเท่านั้น (ตรวจในกล่องจดหมายขยะด้วย)', 200, ['ok' => true]);
        return $this->notice('ขอบคุณที่ตอบแบบสอบถาม', $message, 200, ['ok' => true] + $this->certLinks($cert));
    }

    /** GET of the e-mailed link: only shows a confirm button. Opening it (or a scanner prefetching it) never counts a response. */
    public function confirmForm(Request $r, string $slug, int $pending)
    {
        $survey = $this->svc->findBySlug($slug);
        try {
            $row = $this->svc->pending($survey, $pending, (string) $r->query('n', ''));
            if ($row->response_id !== null && ($done = $this->svc->responseById((int) $row->response_id))) {
                return $this->doneView($survey, 'done', $done->certificate_no, 'คำตอบของท่านได้รับการยืนยันไว้แล้ว');
            }
            $this->svc->rejectIfClosed($survey, true);
        } catch (SurveyRejected $e) {
            return $this->notice($survey->title, $e->getMessage(), $e->status);
        }
        return $this->page('surveys::confirm', ['pageTitle' => 'ยืนยันคำตอบ', 'survey' => $survey, 'action' => $r->fullUrl(), 'displayName' => (string) $row->display_name, 'summary' => $this->svc->pendingSummary($survey, $row)]);
    }

    /** POST of the signed link: counts the response (idempotent). */
    public function confirm(Request $r, string $slug, int $pending)
    {
        $survey = $this->svc->findBySlug($slug);
        try { $row = $this->svc->confirm($survey, $pending, $r->ip(), (string) $r->query('n', '')); }
        catch (SurveyRejected $e) { return $this->notice($survey->title, $e->getMessage(), $e->status); }
        return $this->doneView($survey, 'done', $row->certificate_no);
    }

    /** Fields shown on a certificate: the snapshot taken when it was issued; rows issued before snapshots existed fall back to the live survey. */
    private function certFields(object $row): array
    {
        $snap = json_decode((string) ($row->certificate_snapshot ?? ''), true);
        if (is_array($snap) && isset($snap['title'])) return $snap;
        $conf = json_decode((string) $row->certificate_conf, true) ?: [];
        $when = \Carbon\CarbonImmutable::parse($row->created_at, (string) config('app.timezone'))->setTimezone('Asia/Bangkok')->locale('th');
        $date = $when->translatedFormat('j F').' '.($when->year + 543);
        $school = $this->svc->schoolName();
        return [
            'title' => (string) ($conf['title'] ?? 'เกียรติบัตร'),
            'body' => strtr((string) ($conf['body_template'] ?? ''), ['{name}' => $row->display_name, '{survey}' => $row->survey_title, '{date}' => $date, '{no}' => $row->certificate_no, '{school}' => $school]),
            'signer_name' => (string) ($conf['signer_name'] ?? ''), 'signer_title' => (string) ($conf['signer_title'] ?? ''),
            'survey_title' => $row->survey_title, 'school_name' => $school, 'date' => $date,
        ];
    }

    public function certificate(Request $r, string $no)
    {
        abort_if(Edition::isPublic(), 404); // S2: the public edition holds no responses, only the masked verification rows (see verify)
        $row = $this->svc->certificateRow($no);
        $k = (string) $r->query('k', '');
        // Numbers are sequential; the full certificate (with the name) needs the token that only the recipient's link carries.
        abort_unless($row && $this->svc->certKeyValid($row->certificate_no, $k), 404);
        $f = $this->certFields($row);
        $ctx = app(SchoolWebsiteController::class)->context();
        return response()->view('surveys::certificate', $ctx + ['row' => $row, 'conf' => $f, 'body' => $f['body'], 'date' => $f['date'], 'schoolName' => $f['school_name'],
            'logo' => $ctx['theme']['logo_media_id'] ? url('/media/'.$ctx['theme']['logo_media_id']) : null, 'verifyUrl' => $this->svc->verifyUrl($row->certificate_no)])->header('X-Robots-Tag', 'noindex');
    }

    /** S2: public edition. pub_certificates (number, masked holder, survey title, issue date) is all it knows; lookups share the tight no-key budget. */
    private function verifyPublic(Request $r)
    {
        $no = strtoupper(trim((string) $r->query('no', '')));
        $row = null;
        if ($no !== '') {
            $limiter = 'cert-verify-nokey:'.NetKey::rate($r->ip());
            if (RateLimiter::tooManyAttempts($limiter, self::VERIFY_NOKEY_PER_MINUTE)) return $this->notice('ตรวจสอบเกียรติบัตร', 'ตรวจสอบถี่เกินไป กรุณารอสักครู่แล้วลองใหม่', 429);
            RateLimiter::hit($limiter, 60);
            if (preg_match('/^[A-Z0-9]{1,12}-\d{4}-\d{6,}$/D', $no)) $row = \Illuminate\Support\Facades\DB::table('pub_certificates')->where('number', $no)->first();
        }
        $date = null;
        if ($row && $row->issued_at) {
            $when = \Carbon\CarbonImmutable::parse($row->issued_at, (string) config('app.timezone'))->setTimezone('Asia/Bangkok')->locale('th');
            $date = $when->translatedFormat('j F').' '.($when->year + 543);
        }
        // same rule as the internal verify(): numbers are sequential, so name and survey title are shown only with the recipient's ?k= token
        // (checked against the exported key check value); without it only valid / invalid and the date are revealed
        $k = (string) $r->query('k', '');
        $full = $row && $k !== '' && !empty($row->key_hash) && hash_equals((string) $row->key_hash, hash('sha256', $k));
        return $this->page('surveys::verify', ['pageTitle' => 'ตรวจสอบเกียรติบัตร', 'no' => $no, 'row' => $row ? (object) ['certificate_no' => $row->number] : null, 'date' => $date,
            'surveyTitle' => $full ? $row->survey_title : null, 'shownName' => $full ? $row->holder_name : null, 'full' => $full]);
    }

    public function verify(Request $r)
    {
        if (Edition::isPublic()) return $this->verifyPublic($r);
        $no = trim((string) $r->query('no', ''));
        $k = (string) $r->query('k', '');
        $row = $no === '' ? null : $this->svc->certificateRow($no);
        $full = $row && $this->svc->certKeyValid($row->certificate_no, $k);
        if ($no !== '' && !$full) {
            // Numbers are sequential: without the recipient's key only valid / invalid and the issue date are revealed (no name, no
            // survey title), and these lookups have a much tighter budget than the route-level throttle (IPv6 counted per /64).
            $limiter = 'cert-verify-nokey:'.NetKey::rate($r->ip());
            if (RateLimiter::tooManyAttempts($limiter, self::VERIFY_NOKEY_PER_MINUTE)) {
                return $this->notice('ตรวจสอบเกียรติบัตร', 'ตรวจสอบถี่เกินไป กรุณารอสักครู่แล้วลองใหม่', 429);
            }
            RateLimiter::hit($limiter, 60);
        }
        $f = $row ? $this->certFields($row) : null;
        return $this->page('surveys::verify', [
            'pageTitle' => 'ตรวจสอบเกียรติบัตร', 'no' => $no, 'row' => $row, 'date' => $f['date'] ?? null,
            'surveyTitle' => $full ? ($f['survey_title'] ?? null) : null,
            'shownName' => $full ? $row->display_name : null, 'full' => $full,
        ]);
    }
}
