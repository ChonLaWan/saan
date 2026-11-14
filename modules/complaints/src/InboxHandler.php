<?php

namespace Saan\Modules\Complaints;

use App\Support\Saan\Inbox\InboxRejected;
use App\Support\Saan\Inbox\State;
use App\Support\Saan\Mutation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * Inbox kind "complaint" (F1), run by the internal edition after it pulled and opened an envelope that the public form sealed (or in process in combined/internal).
 *
 * The public side made the receipt number and the tracking code and sealed both inside the envelope; here the case is created and only the HMAC of the
 * tracking code is kept. Idempotent: an envelope that was handled before answers "duplicate" with the case it made (cmp_cases.envelope_hash is unique), and a receipt
 * number that already belongs to another envelope is refused (a sender cannot overwrite or hijack a case).
 *
 * Ceilings that do not depend on anything in the payload (the public side may be breached and can claim anything): cases per minute, cases per day and attachment
 * bytes per day. Above them the envelope stays unacknowledged (a delay, nothing is lost) and the doctor shows an inbox flood. Refusals of bad data are final
 * (InboxRejected -> quarantine); everything else is "try again later".
 */
class InboxHandler
{
    public function handle(array $payload, array $files, array $ctx): array
    {
        $v = Validator::make($payload, [
            'receipt_no' => ['required', 'string', 'regex:'.Complaints::RECEIPT],
            'track_code' => ['required', 'string', 'regex:'.Complaints::CODE],
            'category' => 'required|string|in:'.implode(',', array_keys(Complaints::CATEGORIES)),
            'anonymous' => 'required|boolean',
            'name' => 'nullable|string|max:100', 'email' => 'nullable|email|max:254', 'phone' => 'nullable|string|max:30',
            'subject' => 'required|string|max:160', 'detail' => 'required|string|min:10|max:'.Complaints::MAX_DETAIL,
            'form_nonce' => ['nullable', 'string', 'regex:/^[a-f0-9]{32}$/D'],
        ]);
        if ($v->fails()) throw new InboxRejected('ข้อมูลเรื่องร้องเรียนไม่ถูกต้อง: '.implode(' ', $v->errors()->all()), 422, 'invalid');
        $d = $v->validated();
        $anonymous = (bool) $d['anonymous'];
        if (!$anonymous && (trim((string) ($d['name'] ?? '')) === '' || (trim((string) ($d['email'] ?? '')) === '' && trim((string) ($d['phone'] ?? '')) === ''))) {
            throw new InboxRejected('เรื่องที่ระบุชื่อต้องมีชื่อและช่องทางติดต่อ (อีเมลหรือโทรศัพท์)', 422, 'invalid');
        }
        $school = Complaints::school();
        $inner = !empty($ctx['envelope']) ? (string) $ctx['envelope'] : null;
        $hash = Complaints::trackHash($d['receipt_no'], $d['track_code']);

        $same = $inner !== null ? DB::table('cmp_cases')->where('envelope_hash', $inner)->first() : null;
        if ($same) return ['id' => (int) $same->id, 'duplicate' => true];
        $taken = DB::table('cmp_cases')->where('school_id', $school)->where('receipt_no', $d['receipt_no'])->first();
        if ($taken) {
            // an in-process retry of the same submission (no envelope) is the same case; anything else must not touch an existing case
            if (($inner === null || !empty($d['form_nonce'])) && Complaints::trackMatches((string) $taken->track_hash, $d['receipt_no'], $d['track_code'])) return ['id' => (int) $taken->id, 'duplicate' => true]; // a resend of the same form (same nonce, same text) arrives as a second envelope
            throw new InboxRejected('เลขรับเรื่องนี้ถูกใช้แล้ว', 409, 'receipt_taken');
        }

        $bytes = array_sum(array_map(fn ($f) => (int) ($f['size'] ?? strlen((string) ($f['content'] ?? ''))), $files));
        $this->ceilings($bytes); // direct (combined / internal) filing counts too: a single visitor must not be able to skip the flood ceilings
        if ($bytes > 0) { // a complaint without files is text only and is never held back by a full file quota
            $this->assertOwnQuota($bytes);
            try { \App\Support\StorageQuota::assertRoom($bytes); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException) { throw new \RuntimeException('complaint transient: storage_quota'); }
        }

        // the moment the visitor sent it (sealed inside the envelope, range-checked by Inbox::open), never later than now: a held-back pull must not move the received date or the SLA
        $at = isset($ctx['sent_at']) && is_int($ctx['sent_at']) && $ctx['sent_at'] > 0 ? CarbonImmutable::createFromTimestamp(min($ctx['sent_at'], time())) : CarbonImmutable::now();
        $stored = [];
        // intake never carries an actor: in the combined edition a signed-in staff member may be filing an anonymous complaint, and Mutation::audit would link the case to them
        // (Mutation::withoutActor, not guard->forgetUser: the session guard reloads the user from the session on the next auth()->id())
        try {
            // a plain closure, not fn (): an arrow function would capture $stored by value and the cleanup below would never see the files written inside
            $row = Mutation::withoutActor(function () use ($d, $anonymous, $inner, $hash, $school, $files, $at, &$stored) { return DB::transaction(function () use ($d, $anonymous, $inner, $hash, $school, $files, $at, &$stored) {
                $now = $at;
                $contact = $anonymous ? null : Crypt::encryptString(json_encode(['name' => trim((string) ($d['name'] ?? '')), 'email' => trim((string) ($d['email'] ?? '')), 'phone' => trim((string) ($d['phone'] ?? ''))], JSON_UNESCAPED_UNICODE));
                $case = Mutation::insert('cmp_cases', [
                    'receipt_no' => $d['receipt_no'], 'track_hash' => $hash, 'envelope_hash' => $inner, 'category' => $d['category'], 'anonymous' => $anonymous, 'contact' => $contact,
                    'subject' => trim($d['subject']), 'detail' => trim($d['detail']), 'status' => 'received', 'due_on' => Complaints::dueDate($d['category'], CarbonImmutable::instance($at)),
                    'received_at' => $now, 'status_changed_at' => $now,
                ], Cases::AUDIT);
                foreach ($files as $f) {
                    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
                    $path = 'complaints/'.$school.'/'.bin2hex(random_bytes(16)).'.'.$ext; // our own name: the sender's file name is only a label
                    if (!Cases::disk()->put($path, (string) $f['content'])) throw new \RuntimeException('complaint transient: disk');
                    $stored[] = $path;
                    Mutation::insert('cmp_files', ['case_id' => (int) $case->id, 'name' => mb_substr((string) $f['name'], 0, 160), 'path' => $path, 'mime' => (string) $f['mime'], 'size' => strlen((string) $f['content']),
                        'sha256' => hash('sha256', (string) $f['content']), 'created_at' => $now], Cases::AUDIT);
                }
                Mutation::insert('cmp_actions', ['case_id' => (int) $case->id, 'actor_id' => null, 'action' => 'received', 'detail' => json_encode(['files' => count($files)]), 'created_at' => $now], Cases::AUDIT);
                return $case;
            }); });
        } catch (\Throwable $e) {
            foreach ($stored as $p) { try { Cases::disk()->delete($p); } catch (\Throwable) { /* a leftover file is harmless: nothing references it */ } }
            throw $e;
        }
        $this->count($bytes);
        return ['id' => (int) $row->id];
    }

    /** Complaint attachments have a share of their own, so they can never fill the whole storage quota of the school: config saan.complaints.files_quota_mb, default a quarter of the school quota (2 GB when the quota is unlimited). */
    private function assertOwnQuota(int $bytes): void
    {
        $mb = (int) config('saan.complaints.files_quota_mb', 0);
        $cap = $mb > 0 ? $mb * 1048576 : ((int) \App\Support\StorageQuota::quotaBytes() > 0 ? intdiv((int) \App\Support\StorageQuota::quotaBytes(), 4) : 2048 * 1048576);
        $used = (int) DB::table('cmp_files')->sum('size');
        if ($used + $bytes > $cap) throw new \RuntimeException('complaint transient: complaint_quota');
    }

    /**
     * What the quarantine may keep of a refused complaint (Puller calls this before it stores the payload): no tracking code, and the words and contact of the
     * complainant only sealed with Crypt, so that table never holds a plaintext complaint.
     */
    public static function redact(array $payload): array
    {
        $sealed = [];
        foreach (['name', 'email', 'phone', 'subject', 'detail'] as $k) if (isset($payload[$k]) && is_scalar($payload[$k])) $sealed[$k] = (string) $payload[$k];
        $receipt = isset($payload['receipt_no']) && is_string($payload['receipt_no']) && preg_match(Complaints::RECEIPT, $payload['receipt_no']) ? $payload['receipt_no'] : null;
        $category = isset($payload['category']) && is_string($payload['category']) ? mb_substr($payload['category'], 0, 20) : null;
        return ['redacted' => true, 'receipt_no' => $receipt, 'category' => $category, 'sealed' => Crypt::encryptString(json_encode($sealed, JSON_UNESCAPED_UNICODE))];
    }

    private function limits(): array
    {
        return [max(1, (int) config('saan.inbox.complaint_per_minute', 30)), max(1, (int) config('saan.inbox.complaint_per_day', 500)), max(1, (int) config('saan.inbox.complaint_files_mb_per_day', 500)) * 1048576];
    }

    private function ceilings(int $bytes): void
    {
        [$perMinute, $perDay, $perDayBytes] = $this->limits();
        if (RateLimiter::tooManyAttempts('complaint-inbox-min', $perMinute) || RateLimiter::tooManyAttempts('complaint-inbox-day', $perDay) || RateLimiter::attempts('complaint-inbox-bytes') + $bytes > $perDayBytes) {
            try { State::mark(null, 'inbox.flood', time(), 1); } catch (\Throwable) { /* a signal only */ }
            throw new \RuntimeException('complaint transient: flood_ceiling');
        }
    }

    private function count(int $bytes): void
    {
        RateLimiter::hit('complaint-inbox-min', 60);
        RateLimiter::hit('complaint-inbox-day', 86400);
        if ($bytes > 0) RateLimiter::increment('complaint-inbox-bytes', 86400, $bytes);
    }
}
