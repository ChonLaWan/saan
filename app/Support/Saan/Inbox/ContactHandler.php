<?php

namespace App\Support\Saan\Inbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/** Kind "contact": the public contact form. Writes cms_messages. A pulled envelope that repeats a message of the last ten minutes returns the row made the first time. */
class ContactHandler
{
    public function handle(array $payload, array $files, array $ctx): array
    {
        $v = Validator::make($payload, ['name' => 'required|string|max:100', 'email' => 'required|email|max:254', 'subject' => 'required|string|max:160', 'message' => 'required|string|min:10|max:4000']);
        if ($v->fails()) throw new InboxRejected('ข้อความติดต่อไม่ถูกต้อง: '.implode(' ', $v->errors()->all()), 422, 'invalid');
        $d = $v->validated();
        $school = (int) config('school.school_id');
        if (!empty($ctx['envelope'])) { // only for envelopes that were pulled (a lost ack can deliver one twice); an in-process submit behaves exactly as before the split
            $same = DB::table('cms_messages')->where('school_id', $school)->where('email', $d['email'])->where('subject', $d['subject'])->where('name', $d['name'])
                ->where('created_at', '>=', now()->subMinutes(10))->orderBy('id')->get(['id', 'message']);
            foreach ($same as $row) if ($row->message === $d['message']) return ['id' => (int) $row->id, 'duplicate' => true];
        }
        // Ceilings on envelope-sourced messages that do not depend on anything in the payload (the sender is the public side, which may be breached): above them the
        // envelope is left unacknowledged (a delay, nothing is lost) and the doctor shows an inbox flood.
        if (!empty($ctx['envelope'])) {
            $perMinute = max(1, (int) config('saan.inbox.contact_per_minute', 120));
            $perDay = max(1, (int) config('saan.inbox.contact_per_day', 3000));
            if (RateLimiter::tooManyAttempts('contact-inbox-min', $perMinute) || RateLimiter::tooManyAttempts('contact-inbox-day', $perDay)) {
                try { State::mark(null, 'inbox.flood', time(), 1); } catch (\Throwable) { /* a signal only */ }
                throw new \RuntimeException('contact transient: flood_ceiling');
            }
            RateLimiter::hit('contact-inbox-min', 60);
            RateLimiter::hit('contact-inbox-day', 86400);
        }
        $id = (int) DB::table('cms_messages')->insertGetId($d + ['school_id' => $school, 'created_at' => now(), 'updated_at' => now()]);
        return ['id' => $id];
    }
}
