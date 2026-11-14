<?php

namespace Saan\Modules\Complaints\Http;

use App\Http\Controllers\SchoolWebsiteController;
use App\Support\Saan\Inbox;
use App\Support\Saan\Inbox\InboxRejected;
use App\Support\Saan\NetKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Saan\Modules\Complaints\Complaints;
use Saan\Modules\Complaints\PublicStatus;
use Saan\Modules\Complaints\Reports;
use Saan\Modules\Complaints\Turnstile;

/**
 * The public complaint form (F1). The receipt number and the tracking code are made HERE, shown once in the response and sealed inside the envelope; this
 * side keeps nothing about the complaint (no row, no session flash, no log). Anti-bot: honeypot, per /64 and /48 limits, optional Turnstile.
 */
class PublicController
{
    private function page(string $view, array $data, int $status = 200)
    {
        // the page's own values come first: the site context also has a 'categories' (news categories) and a null 'pageTitle' that must not win over the complaint ones
        return response()->view('complaints::'.$view, $data + app(SchoolWebsiteController::class)->context(), $status)->withHeaders([
            'Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function form(Request $r)
    {
        return $this->page('form', $this->formData());
    }

    /** @param array<string,mixed> $old what the visitor typed (echoed in the same response only, never stored); $nonce keeps one form identity across a refused try */
    private function formData(array $old = [], ?string $nonce = null): array
    {
        return ['old' => $old, 'formNonce' => $nonce ?? bin2hex(random_bytes(16)), 'pageTitle' => 'ร้องเรียนและแจ้งเรื่อง', 'categories' => Complaints::CATEGORIES, 'turnstile' => Turnstile::enabled() ? Turnstile::siteKey() : null,
            'reports' => array_map(fn ($p) => ['fiscal_year' => $p['fiscal_year']], Reports::published()), 'maxFiles' => Inbox::MAX_FILES];
    }

    private function refuse(Request $r, string $message, int $status = 422)
    {
        if ($r->expectsJson()) return response()->json(['message' => $message], $status);
        // the typed text goes back in THIS response only (no flash, no session, no log): this edition keeps no plaintext of a complaint
        $old = [];
        foreach (['category' => 20, 'name' => 100, 'email' => 254, 'phone' => 30, 'subject' => 160, 'detail' => Complaints::MAX_DETAIL] as $k => $max) $old[$k] = is_scalar($r->input($k)) ? mb_substr((string) $r->input($k), 0, $max) : '';
        $old['anonymous'] = $r->boolean('anonymous');
        $old['consent'] = $r->boolean('consent');
        $nonce = is_string($r->input('form_nonce')) && preg_match('/^[a-f0-9]{32}$/D', $r->input('form_nonce')) ? $r->input('form_nonce') : null;
        return $this->page('form', $this->formData($old, $nonce) + ['error' => $message], $status);
    }

    public function submit(Request $r)
    {
        // every try counts, valid or not (an IPv6 visitor rotates inside its /64, a hosting customer inside a /48)
        $k64 = 'complaint-submit:'.NetKey::rate($r->ip());
        $k48 = 'complaint-submit-wide:'.NetKey::wide($r->ip());
        // 30 an hour would let ONE network use up the school-wide daily ceiling of the handler (saan.inbox.complaint_per_day) and close the form for everybody:
        // of the complaints accepted per day one network gets a fifth (counted below, after a filing went through; failed tries only count per hour)
        $kDay = 'complaint-submit-day:'.NetKey::wide($r->ip());
        $dayShare = max(20, intdiv(max(1, (int) config('saan.inbox.complaint_per_day', 500)), 5));
        if (RateLimiter::tooManyAttempts($k64, 5) || RateLimiter::tooManyAttempts($k48, 30) || RateLimiter::tooManyAttempts($kDay, $dayShare)) return $this->refuse($r, 'ส่งเรื่องถี่เกินไป กรุณารอสักครู่แล้วลองใหม่', 429);
        RateLimiter::hit($k64, 600);
        RateLimiter::hit($k48, 3600);

        // Validator::make, not $r->validate(): a failed validate() flashes every input to the session store, which would keep the complaint text on this edition
        $v = \Illuminate\Support\Facades\Validator::make($r->all(), [
            'category' => 'required|string|in:'.implode(',', array_keys(Complaints::CATEGORIES)),
            'anonymous' => 'nullable|boolean',
            'name' => 'nullable|string|max:100', 'email' => 'nullable|email|max:254', 'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-() ]{6,30}$/'],
            'subject' => 'required|string|max:160', 'detail' => 'required|string|min:10|max:'.Complaints::MAX_DETAIL,
            'website' => 'nullable|max:0', // honeypot
            'form_nonce' => ['nullable', 'string', 'regex:/^[a-f0-9]{32}$/D'],
            'consent' => 'nullable|boolean',
            'files' => 'nullable|array|max:'.Inbox::MAX_FILES, 'files.*' => 'file',
        ], [
            'category.required' => 'กรุณาเลือกประเภทเรื่อง', 'subject.required' => 'กรุณาระบุหัวข้อเรื่อง', 'detail.required' => 'กรุณาระบุรายละเอียด', 'detail.min' => 'กรุณาระบุรายละเอียดอย่างน้อย 10 ตัวอักษร',
            'email.email' => 'กรุณาตรวจสอบอีเมล', 'phone.regex' => 'เบอร์โทรศัพท์ไม่ถูกต้อง', 'website.max' => 'ไม่สามารถส่งเรื่องได้', 'files.max' => 'แนบไฟล์ได้ไม่เกิน '.Inbox::MAX_FILES.' ไฟล์',
        ]);
        if ($v->fails()) return $this->refuse($r, (string) $v->errors()->first());
        $d = $v->validated();
        $anonymous = (bool) ($d['anonymous'] ?? false);
        if (!$anonymous) {
            if (trim((string) ($d['name'] ?? '')) === '') return $this->refuse($r, 'กรุณาระบุชื่อ หรือเลือกส่งโดยไม่ระบุชื่อ');
            if (trim((string) ($d['email'] ?? '')) === '' && trim((string) ($d['phone'] ?? '')) === '') return $this->refuse($r, 'กรุณาระบุอีเมลหรือเบอร์โทรศัพท์สำหรับติดต่อกลับ หรือเลือกส่งโดยไม่ระบุชื่อ');
            if (!$r->boolean('consent')) return $this->refuse($r, 'กรุณายืนยันการให้โรงเรียนใช้ข้อมูลติดต่อเพื่อดำเนินการเรื่องนี้');
        }
        if (!Turnstile::verify($r->input('cf-turnstile-response'))) return $this->refuse($r, 'ไม่ผ่านการตรวจสอบว่าเป็นผู้ใช้จริง กรุณาลองใหม่อีกครั้ง');

        // a form that carries its nonce gets receipt and code derived from it: a double click or a resend of the same form gives the same pair, so it stays ONE case
        if (!empty($d['form_nonce'])) [$receipt, $code] = Complaints::deriveIds($d['form_nonce'], json_encode([$d['category'], $anonymous, trim($d['subject']), trim($d['detail'])], JSON_UNESCAPED_UNICODE));
        else [$receipt, $code] = [Complaints::newReceipt(), Complaints::newTrackingCode()];
        $files = [];
        $bytes = 0;
        foreach ((array) $r->file('files', []) as $f) {
            if ($f === null) continue;
            if (!$f->isValid()) return $this->refuse($r, 'อัปโหลดไฟล์แนบไม่สำเร็จ');
            $files[] = ['name' => (string) $f->getClientOriginalName(), 'path' => (string) $f->getRealPath()];
            $bytes += (int) $f->getSize();
        }
        // attachment bytes per network and hour: one client cannot push the file quota full with a handful of 20 MB uploads (Turnstile, when set, was already required above)
        $kb = 'complaint-bytes-wide:'.NetKey::wide($r->ip());
        $cap = max(1, (int) config('saan.complaints.net_mb_per_hour', 60)) * 1048576;
        if ($bytes > 0 && RateLimiter::attempts($kb) + $bytes > $cap) return $this->refuse($r, 'แนบไฟล์จากเครือข่ายนี้มากเกินไป กรุณาลองใหม่ภายหลังหรือส่งโดยไม่แนบไฟล์', 429);
        if ($bytes > 0) RateLimiter::increment($kb, 3600, $bytes);
        $payload = ['receipt_no' => $receipt, 'track_code' => $code, 'category' => $d['category'], 'anonymous' => $anonymous, 'subject' => trim($d['subject']), 'detail' => trim($d['detail'])];
        if (!empty($d['form_nonce'])) $payload['form_nonce'] = $d['form_nonce'];
        // an anonymous complaint carries no contact field at all (and the network address of the visitor is never part of the envelope)
        if (!$anonymous) $payload += ['name' => trim((string) $d['name']), 'email' => trim((string) ($d['email'] ?? '')), 'phone' => trim((string) ($d['phone'] ?? ''))];
        try {
            Inbox::submit('complaint', $payload, $files);
        } catch (InboxRejected $e) {
            return $this->refuse($r, $e->getMessage(), $e->status >= 500 ? 503 : 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->refuse($r, implode(' ', $e->validator->errors()->all()));
        } catch (\RuntimeException $e) {
            // combined / internal: the handler runs in this process, so a full file quota, a failed disk write or a database outage is a temporary
            // refusal, not a 500. Only the class is logged: the message may carry text of the complaint.
            \Illuminate\Support\Facades\Log::warning('[WARN] complaint submit refused: '.get_class($e));
            return $this->refuse($r, $files
                ? 'ระบบรับไฟล์แนบไม่ได้ชั่วคราว กรุณาลองใหม่ภายหลังหรือส่งโดยไม่แนบไฟล์'
                : 'ระบบรับเรื่องไม่ได้ชั่วคราว กรุณาลองใหม่ภายหลัง ข้อความที่พิมพ์ไว้ยังอยู่ในแบบฟอร์ม', 503);
        }
        RateLimiter::hit($kDay, 86400);
        if ($r->expectsJson()) return response()->json(['receipt_no' => $receipt, 'tracking_code' => Complaints::formatCode($code)], 201)->header('Cache-Control', 'no-store');
        return $this->page('receipt', ['pageTitle' => 'รับเรื่องแล้ว', 'receipt' => $receipt, 'code' => Complaints::formatCode($code), 'anonymous' => $anonymous], 201);
    }

    public function statusForm()
    {
        return $this->page('status', ['pageTitle' => 'ตรวจสอบสถานะเรื่อง', 'result' => null, 'error' => null]);
    }

    public function status(Request $r)
    {
        // counted before anything is read: a wrong receipt, a wrong code and a right pair all cost the same attempt
        if (PublicStatus::throttled($r->ip())) return $this->page('status', ['pageTitle' => 'ตรวจสอบสถานะเรื่อง', 'result' => null, 'error' => PublicStatus::THROTTLED], 429);
        $result = PublicStatus::lookup($r->input('receipt_no'), $r->input('tracking_code'));
        // one answer for "no such receipt", "wrong code" and "malformed input": the page never says whether a receipt number exists
        if ($result === null) return $this->page('status', ['pageTitle' => 'ตรวจสอบสถานะเรื่อง', 'result' => null, 'error' => PublicStatus::GENERIC], 404);
        return $this->page('status', ['pageTitle' => 'สถานะเรื่อง '.$result['receipt_no'], 'result' => $result, 'error' => null]);
    }

    public function report(int $year)
    {
        $p = Reports::find($year) ?? abort(404);
        return $this->page('report', ['pageTitle' => 'รายงานสรุปเรื่องร้องเรียน '.$p['report']['label'], 'report' => $p['report'], 'statuses' => Complaints::STATUSES, 'publishedAt' => $p['published_at']]);
    }

    public function reportCsv(int $year)
    {
        $p = Reports::find($year) ?? abort(404);
        return response(Complaints::reportCsv($p['report']), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="complaints-report-'.$year.'.csv"', 'X-Content-Type-Options' => 'nosniff']);
    }
}
