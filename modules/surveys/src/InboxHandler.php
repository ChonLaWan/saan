<?php

namespace Saan\Modules\Surveys;

use App\Support\Saan\Inbox\InboxRejected;
use App\Support\Saan\Inbox\State;
use App\Support\Saan\NetKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Inbox kind "survey" (S5), run by the internal edition after it pulled and opened an envelope that the public form sealed.
 * It goes through the same SurveyService::submit as a direct submission: closed/full checks, uniqueness, per-network limits, and for the
 * name_email mode the verification mail (sent from HERE, so the public edition needs no SMTP secrets). Idempotent: a repeated envelope ends
 * in the survey's own duplicate rule and answers "duplicate".
 */
class InboxHandler
{
    /** Refusals that clear with time or a retry: the envelope stays on the public side and is tried again on the next pull. */
    /** Wrong or used roster codes per network (the ip the envelope names) in 10 minutes: a delay for that network only, never a block of the survey. */
    private const CODE_PER_NET = 60;
    /** Roster attempts per survey per hour that the payload hints cannot influence: at least this many, or 1.5 times the roster, whichever is more. Above it envelopes wait. */
    private const ROSTER_HOUR_MIN = 300;
    /** Anonymous answers per survey per hour = this many times the per-network budget of the survey. */
    private const ANON_FACTOR = 10;
    private const TRANSIENT = ['mail_failed', 'pending_race', 'mail_limit', 'mail_ip_limit', 'mail_survey_limit', 'mail_hour_limit'];

    public function handle(array $payload, array $files, array $ctx): array
    {
        $slug = $payload['slug'] ?? null;
        $input = $payload['input'] ?? null;
        if (!is_string($slug) || !preg_match('/^[a-z0-9-]{3,80}$/D', $slug) || !is_array($input)) throw new InboxRejected('ข้อมูลแบบสอบถามในซองไม่ถูกต้อง', 422, 'invalid');
        $svc = app(SurveyService::class);
        $survey = DB::table('srv_surveys')->where('school_id', $svc->school())->where('slug', $slug)->where('status', '!=', 'draft')->first();
        if (!$survey) throw new InboxRejected('ไม่พบแบบสอบถาม '.$slug.' (อาจถูกลบหรือยังเป็นร่าง)', 422, 'survey_gone');
        $ip = is_string($payload['ip'] ?? null) && filter_var($payload['ip'], FILTER_VALIDATE_IP) ? $payload['ip'] : null;
        $cookie = is_string($payload['cookie'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $payload['cookie']) ? $payload['cookie'] : null;
        $session = is_string($payload['session'] ?? null) ? substr($payload['session'], 0, 100) : '';
        // The ip, cookie and session come from the public side, which may be breached: they are hints only. The survey-wide ceilings do not depend on them.
        $anonKey = 'srv-inbox-anon:'.$survey->id;
        $guard = null;
        // wrong or used codes only: a full counter delays more wrong codes, never a valid one
        $attemptKey = 'srv-inbox-roster-bad:'.$survey->id;
        $validCode = false;
        if ($survey->identity_mode === 'roster_code') {
            // A valid unused roster code is never held back by anybody else's wrong codes (an anonymous sender can fill any counter with junk).
            // Wrong codes count against the sender's own network AND one hourly ceiling of the survey that the payload hints cannot dodge: above it,
            // further wrong codes wait on the public side, so guessing is slowed to that ceiling while real students still get through.
            // A breached public side that already knows valid codes is not stopped here: the defence for that is unguessable codes (the import warns about weak ones).
            $guard = 'srv-inbox-net:'.$survey->id.':'.NetKey::rate($ip);
            $validCode = $svc->unusedRosterRow($survey, $input['code'] ?? null) !== null;
            if (!$validCode) {
                $ceiling = max(self::ROSTER_HOUR_MIN, (int) ceil(1.5 * (int) DB::table('srv_roster')->where('survey_id', $survey->id)->count()));
                if (RateLimiter::tooManyAttempts($attemptKey, $ceiling)) { State::mark(null, 'inbox.badcode', time(), 1); throw new \RuntimeException('survey transient: roster_ceiling'); }
                if (RateLimiter::tooManyAttempts($guard, self::CODE_PER_NET)) throw new \RuntimeException('survey transient: code_net');
            }
        } elseif ($survey->identity_mode === 'anonymous') {
            if (RateLimiter::tooManyAttempts($anonKey, max(1, (int) ($survey->anon_ip_hour ?? SurveyService::ANON_PER_IP_HOUR)) * self::ANON_FACTOR)) throw new \RuntimeException('survey transient: anon_global');
        }
        try {
            $r = $svc->submit($survey, array_intersect_key($input, array_flip(['a', 'name', 'email', 'code'])), $ip, $cookie, $session, false);
            if ($survey->identity_mode === 'anonymous') RateLimiter::hit($anonKey, 3600);
        } catch (ValidationException $e) {
            if ($guard && !$validCode) { RateLimiter::hit($attemptKey, 3600); $this->wrongCode($guard); }
            throw $e;
        } catch (SurveyRejected $e) {
            if ($guard && !$validCode && in_array($e->reason, ['code_limit', 'duplicate', 'code_used'], true)) { RateLimiter::hit($attemptKey, 3600); $this->wrongCode($guard); } // probing a code that exists but is used
            if ($e->reason === 'duplicate') return ['status' => 'duplicate'];
            // the per-session / per-network budget of the roster form is counted on hints the sender chose: a VALID code that runs into it waits for the next pull instead of being lost
            if ($validCode && $e->reason === 'code_limit') throw new \RuntimeException('survey transient: code_limit');
            if (in_array($e->reason, self::TRANSIENT, true)) throw new \RuntimeException('survey transient: '.$e->reason);
            throw new InboxRejected($e->getMessage(), $e->status, $e->reason);
        }
        return ['status' => $r['status'], 'response_id' => $r['response']?->id];
    }

    /** A wrong or used roster code that came through an envelope: counted against that network (a delay for it only), and for the doctor. */
    private function wrongCode(string $key): void
    {
        RateLimiter::hit($key, 600);
        State::mark(null, 'inbox.badcode', time(), 1);
    }
}
