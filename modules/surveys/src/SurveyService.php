<?php

namespace Saan\Modules\Surveys;

use App\Support\Saan\Edition;
use App\Support\Saan\NetKey;
use App\Support\Saan\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Saan\Modules\Surveys\Mail\SurveyVerifyMail;

/**
 * Response pipeline shared by every identity mode.
 *
 * Uniqueness: srv_responses has UNIQUE(survey_id, respondent_key). respondent_key is an HMAC (app key) over
 * survey id + normalised e-mail / roster code / anonymous cookie token, so raw e-mails and codes are never stored.
 * Anonymous mode cannot guarantee uniqueness (a person can clear cookies or change network); it only makes repeat
 * submissions inconvenient (signed cookie + per-IP hourly budget).
 */
class SurveyService
{
    public const MODES = ['anonymous', 'name_email', 'roster_code'];
    public const TYPES = ['single', 'multi', 'scale', 'text'];
    /** Default per-survey budget (editable per survey: srv_surveys.anon_ip_hour). Mobile carriers share one IP (CGNAT), so it is generous. */
    public const ANON_PER_IP_HOUR = 300;
    public const VERIFY_HOURS = 24;
    public const MAIL_PER_IP_HOUR = 20;
    /** Below typical shared-host outbound quotas (a few hundred mails per hour for the whole account). */
    public const MAIL_PER_SURVEY_MINUTE = 30;
    /** Whole-system verification mails per hour (all surveys together); override with config('saan.mail.max_per_hour'). */
    public const MAIL_PER_HOUR_ALL = 100;
    public const ROSTER_PER_SESSION = 15;
    public const ROSTER_PER_IP = 200;
    /** Wrong roster codes per /48 (IPv6 site) in 10 minutes. */
    public const ROSTER_PER_WIDE = 400;
    /** Wrong roster codes per /48 (or IPv4 address) per HOUR on direct submissions, at least: 48 % of the survey-wide ceiling below (so this is 48 % of ROSTER_WRONG_MIN); one or two networks alone never trip the survey-wide lock. */
    public const ROSTER_PER_WIDE_HOUR = 240;
    /** Roster attempts that do not end in a response, for the WHOLE survey per hour: at least this many (above what one /64 or /48 may spend alone), or 1.5 times the roster. Above it code entry is locked for ROSTER_LOCK_SECONDS. */
    public const ROSTER_WRONG_MIN = 500;
    public const ROSTER_LOCK_SECONDS = 1800;
    /** Verification mails per hour one network may take out of the shared host-wide budget: 1/4 of it. */
    public const MAIL_HOUR_NET_SHARE = 4;

    public function school(): int
    {
        return (int) config('school.school_id');
    }

    // ---- hashing and normalisation ------------------------------------------------------------

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Identity of the mailbox for one-response-per-person: "+tag" and dots in the local part are dropped
     * only for gmail.com / googlemail.com (elsewhere "+" may be part of a real mailbox name). The mail is still sent to the typed address.
     */
    public static function emailIdentity(string $email): string
    {
        $email = self::normaliseEmail($email);
        $at = strrpos($email, '@');
        if ($at === false) return $email;
        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $plus = strpos($local, '+');
            if ($plus !== false && $plus > 0) $local = substr($local, 0, $plus);
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }
        return $local.'@'.$domain;
    }

    public static function normaliseCode(string $code): string
    {
        return strtoupper(preg_replace('/\s+/u', '', trim($code)) ?? '');
    }

    public static function normaliseName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    }

    public function key(int $surveyId, string $mode, string $value): string
    {
        return hash_hmac('sha256', 'srv|'.$surveyId.'|'.$mode.'|'.$value, \App\Support\Saan\HashKey::secret());
    }

    /** Same key under every acceptable secret (current first), for rows hashed before a key rotation. @return string[] */
    public function keys(int $surveyId, string $mode, string $value): array
    {
        return array_values(array_unique(array_map(fn ($k) => hash_hmac('sha256', 'srv|'.$surveyId.'|'.$mode.'|'.$value, $k), \App\Support\Saan\HashKey::candidates())));
    }

    /** $surveyId salts the hash per survey, so the stored ip_hash of one survey cannot be matched against another (0 = unsalted, legacy callers). */
    public function ipHash(?string $ip, int $surveyId = 0): string
    {
        // IPv6 is reduced to its /64 so rotating addresses inside one subscriber's prefix share one budget.
        return hash_hmac('sha256', 'ip|'.($surveyId > 0 ? $surveyId.'|' : '').NetKey::rate($ip), \App\Support\Saan\HashKey::secret());
    }

    /** Hash of the wider network key (IPv6 /48) for the coarser ceilings. */
    public function wideHash(?string $ip): string
    {
        return hash_hmac('sha256', 'ipw|'.NetKey::wide($ip), \App\Support\Saan\HashKey::secret());
    }

    /** Is roster-code entry locked for this survey (too many wrong codes from everybody together)? */
    public static function codeLocked(int $surveyId): bool
    {
        return \Illuminate\Support\Facades\Cache::has('srv-code-lock:'.$surveyId);
    }

    public static function isUniqueViolation(QueryException $e): bool
    {
        return \App\Support\Saan\DbError::isUnique($e);

    }

    /** Deadlock / lock-wait (MariaDB 1213, 1205): safe to report as "try again". */
    public static function isLockConflict(QueryException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true);
    }

    /** True when the question carries an answer key ("0" counts as a key). */
    public static function hasCorrect(array $q): bool
    {
        return array_key_exists('correct', $q) && $q['correct'] !== null && $q['correct'] !== '' && $q['correct'] !== [];
    }

    /** Token that must accompany a certificate number to open the full certificate (numbers are sequential and guessable). */
    public function certKey(string $no, ?string $key = null): string
    {
        return substr(hash_hmac('sha256', 'cert|'.strtoupper(trim($no)), $key ?? \App\Support\Saan\HashKey::secret()), 0, 10);
    }

    /** Constant-time check of a ?k= value against the current key and, after an APP_KEY rotation, the previous ones (issued links keep working). */
    public function certKeyValid(string $no, string $k): bool
    {
        if ($k === '') return false;
        foreach (\App\Support\Saan\HashKey::candidates() as $key) {
            if (hash_equals($this->certKey($no, $key), $k)) return true;
        }
        return false;
    }

    public function certUrl(string $no): string
    {
        return url('/portal/certificates/'.$no).'?k='.$this->certKey($no);
    }

    public function verifyUrl(string $no, bool $withKey = true): string
    {
        return url('/portal/certificates/verify').'?no='.rawurlencode($no).($withKey ? '&k='.$this->certKey($no) : '');
    }

    /** Partially hidden name for the public verify page: first name plus the first letter of the last word. */
    public static function maskName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $first = static fn (string $s): string => preg_match('/^\X/u', $s, $m) ? $m[0] : '';
        if (count($parts) < 2) return $first((string) ($parts[0] ?? '')).'...';
        $last = array_pop($parts);
        return implode(' ', $parts).' '.$first($last).'.';
    }

    public static function dbTime(?string $value): ?string
    {
        if ($value === null || trim($value) === '') return null;
        return \Carbon\CarbonImmutable::parse($value, 'Asia/Bangkok')->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
    }

    public static function isoTime(?string $value): ?string
    {
        return $value ? \Carbon\CarbonImmutable::parse($value, (string) config('app.timezone'))->setTimezone('Asia/Bangkok')->format('Y-m-d\TH:i') : null;
    }

    // ---- survey definition --------------------------------------------------------------------

    public function find(int $id): object
    {
        return DB::table('srv_surveys')->where('school_id', $this->school())->where('id', $id)->first() ?? abort(404);
    }

    public function findBySlug(string $slug): object
    {
        return DB::table('srv_surveys')->where('school_id', $this->school())->where('slug', $slug)->where('status', '!=', 'draft')->first() ?? abort(404);
    }

    public function questions(object $survey): array
    {
        return json_decode((string) $survey->questions, true) ?: [];
    }

    public function certificate(object $survey): array
    {
        $c = json_decode((string) ($survey->certificate ?? ''), true);
        return is_array($c) && !empty($c['enabled']) ? $c : [];
    }

    /** Question list after schema validation: drops irrelevant fields and checks cross-field rules. */
    public function normaliseQuestions(array $questions): array
    {
        $out = [];
        foreach ($questions as $i => $q) {
            $path = 'questions.'.$i;
            $type = $q['type'];
            $row = ['key' => $q['key'], 'type' => $type, 'label' => trim($q['label']), 'required' => (bool) ($q['required'] ?? false)];
            if (in_array($type, ['single', 'multi'], true)) {
                $options = array_values(array_map(fn ($o) => trim($o), $q['options'] ?? []));
                if (count($options) < 2 || count(array_unique($options)) !== count($options) || in_array('', $options, true)) {
                    throw ValidationException::withMessages([$path.'.options' => 'ตัวเลือกต้องมีอย่างน้อย 2 ข้อ ไม่ซ้ำกันและไม่ว่าง']);
                }
                $row['options'] = $options;
                $correct = $q['correct'] ?? null;
                if ($correct !== null && !is_scalar($correct) && !(is_array($correct) && array_filter($correct, 'is_scalar') === $correct)) {
                    throw ValidationException::withMessages([$path.'.correct' => 'เฉลยต้องเป็นข้อความตัวเลือก']);
                }
                if (self::hasCorrect($q)) {
                    $list = array_values(array_unique(array_map('strval', (array) $correct)));
                    if (array_diff($list, $options) || ($type === 'single' && count($list) !== 1)) {
                        throw ValidationException::withMessages([$path.'.correct' => 'เฉลยต้องเป็นหนึ่งในตัวเลือก']);
                    }
                    $row['correct'] = $type === 'single' ? $list[0] : $list;
                }
            } elseif ($type === 'scale') {
                $row['max'] = (int) ($q['max'] ?? 5);
            }
            $out[] = $row;
        }
        return $out;
    }

    /** Structure that must not change once responses exist (labels may). */
    public function structure(array $questions): array
    {
        return array_map(function ($q) { unset($q['label']); return $q; }, $questions);
    }

    // ---- availability -------------------------------------------------------------------------

    /** null when the survey accepts responses now, otherwise a reason code. */
    public function closedReason(object $survey, bool $countResponses = false): ?string
    {
        if ($survey->status === 'draft') return 'draft';
        if ($survey->status !== 'open') return 'closed';
        $now = now()->format('Y-m-d H:i:s');
        if ($survey->opens_at && $survey->opens_at > $now) return 'not_open';
        if ($survey->closes_at && $survey->closes_at <= $now) return 'expired';
        if ($countResponses && $survey->max_responses !== null && DB::table('srv_responses')->where('survey_id', $survey->id)->count() >= (int) $survey->max_responses) return 'full';
        return null;
    }

    public function rejectIfClosed(object $survey, bool $countResponses = false): void
    {
        $reason = $this->closedReason($survey, $countResponses);
        if ($reason === null) return;
        $messages = ['draft' => 'ยังไม่เปิดให้ตอบ', 'closed' => 'แบบสอบถามนี้ปิดรับคำตอบแล้ว', 'not_open' => 'แบบสอบถามนี้ยังไม่ถึงเวลาเปิดรับคำตอบ', 'expired' => 'แบบสอบถามนี้หมดเวลารับคำตอบแล้ว', 'full' => 'แบบสอบถามนี้ได้รับคำตอบครบจำนวนที่กำหนดแล้ว'];
        throw new SurveyRejected($reason === 'not_open' || $reason === 'draft' ? 403 : 410, $messages[$reason], $reason);
    }

    // ---- answers and score --------------------------------------------------------------------

    /** @return array<string,mixed> cleaned answers keyed by question key */
    public function validateAnswers(array $questions, mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $answers = [];
        $errors = [];
        foreach ($questions as $q) {
            $key = $q['key'];
            $raw = $input[$key] ?? null;
            $field = 'a.'.$key;
            if ($q['type'] === 'multi') {
                $raw = is_array($raw) ? array_values(array_unique(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $raw))) : (($raw === null || $raw === '') ? [] : [is_scalar($raw) ? (string) $raw : '']);
                $empty = $raw === [];
            } else {
                $raw = is_scalar($raw) ? trim((string) $raw) : '';
                $empty = $raw === '';
            }
            if ($empty) {
                if ($q['required']) $errors[$field] = 'กรุณาตอบข้อนี้';
                continue;
            }
            switch ($q['type']) {
                case 'single':
                    if (!in_array($raw, $q['options'], true)) { $errors[$field] = 'ตัวเลือกไม่ถูกต้อง'; continue 2; }
                    break;
                case 'multi':
                    if (array_diff($raw, $q['options'])) { $errors[$field] = 'ตัวเลือกไม่ถูกต้อง'; continue 2; }
                    break;
                case 'scale':
                    if (!preg_match('/^\d{1,2}$/D', $raw) || (int) $raw < 1 || (int) $raw > (int) ($q['max'] ?? 5)) { $errors[$field] = 'ระดับคะแนนไม่ถูกต้อง'; continue 2; }
                    $raw = (int) $raw;
                    break;
                case 'text':
                    if (mb_strlen($raw) > 2000) { $errors[$field] = 'ยาวเกิน 2,000 ตัวอักษร'; continue 2; }
                    break;
            }
            $answers[$key] = $raw;
        }
        if ($errors) throw ValidationException::withMessages($errors);
        return $answers;
    }

    /** Percentage of auto-scored questions answered correctly, or null when no question has an answer key. */
    public function score(array $questions, array $answers): ?int
    {
        $scored = 0;
        $right = 0;
        foreach ($questions as $q) {
            if (!self::hasCorrect($q)) continue;
            $scored++;
            $given = $answers[$q['key']] ?? null;
            if ($q['type'] === 'single') $right += $given === $q['correct'] ? 1 : 0;
            elseif (is_array($given)) { $a = $given; $b = $q['correct']; sort($a); sort($b); $right += $a === $b ? 1 : 0; }
        }
        return $scored === 0 ? null : (int) round(100 * $right / $scored);
    }

    // ---- submit (all modes) -------------------------------------------------------------------

    /**
     * Public edition (S5): everything that can be judged from the published survey alone, before the answer is sealed for the internal edition.
     * Uniqueness, roster codes and the verification mail are decided by the internal edition after it pulled the envelope.
     * @return array{a:array,name?:string,email?:string,code?:string}
     */
    public function precheck(object $survey, array $input): array
    {
        $this->rejectIfClosed($survey);
        $out = ['a' => $this->validateAnswers($this->questions($survey), $input['a'] ?? [])];
        switch ($survey->identity_mode) {
            case 'anonymous':
                break;
            case 'name_email':
                $name = self::normaliseName(is_scalar($input['name'] ?? null) ? (string) $input['name'] : '');
                $email = self::normaliseEmail(is_scalar($input['email'] ?? null) ? (string) $input['email'] : '');
                $errors = [];
                if (mb_strlen($name) < 2 || mb_strlen($name) > 160) $errors['name'] = 'กรุณากรอกชื่อ-สกุล (2-160 ตัวอักษร)';
                if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'รูปแบบอีเมลไม่ถูกต้อง';
                if ($errors) throw ValidationException::withMessages($errors);
                $out += ['name' => $name, 'email' => $email];
                break;
            case 'roster_code':
                $code = self::normaliseCode(is_scalar($input['code'] ?? null) ? (string) $input['code'] : '');
                if ($code === '' || strlen($code) > 60) throw ValidationException::withMessages(['code' => 'กรุณากรอกรหัสจากรายชื่อ']);
                $out += ['code' => $code];
                break;
            default:
                throw new SurveyRejected(422, 'รูปแบบการระบุตัวตนไม่ถูกต้อง');
        }
        return $out;
    }

    /**
     * @param array{a?:mixed,name?:mixed,email?:mixed,code?:mixed} $input
     * @return array{status:string,response:?object,cookie:?string}
     */
    public function submit(object $survey, array $input, ?string $ip, ?string $cookieToken, string $sessionId = '', bool $refund = true): array
    {
        $this->rejectIfClosed($survey);
        $answers = $this->validateAnswers($this->questions($survey), $input['a'] ?? []);
        $ipHash = $this->ipHash($ip, (int) $survey->id);
        $wide = $this->wideHash($ip);
        return match ($survey->identity_mode) {
            'anonymous' => $this->submitAnonymous($survey, $answers, $ipHash, $cookieToken),
            'name_email' => $this->submitEmail($survey, $answers, $input, $ipHash, $wide, $refund),
            'roster_code' => $this->submitRoster($survey, $answers, $input, $ipHash, $sessionId, $refund, $wide),
            default => throw new SurveyRejected(422, 'รูปแบบการระบุตัวตนไม่ถูกต้อง'),
        };
    }

    private function submitAnonymous(object $survey, array $answers, string $ipHash, ?string $cookie): array
    {
        $token = $cookie !== null && preg_match('/^[a-f0-9]{32}$/D', $cookie) ? $cookie : bin2hex(random_bytes(16));
        $recent = DB::table('srv_responses')->where('survey_id', $survey->id)->where('ip_hash', $ipHash)->where('created_at', '>=', now()->subHour())->count();
        $budget = max(1, (int) ($survey->anon_ip_hour ?? self::ANON_PER_IP_HOUR));
        if ($recent >= $budget) throw new SurveyRejected(429, 'มีการส่งคำตอบจากเครือข่ายนี้จำนวนมาก กรุณาลองใหม่ภายหลัง', 'ip_limit');
        if (DB::table('srv_responses')->where('survey_id', $survey->id)->whereIn('respondent_key', $this->keys($survey->id, 'anonymous', $token))->exists()) {
            throw new SurveyRejected(409, 'ท่านตอบแบบสอบถามนี้แล้ว หนึ่งคนตอบได้หนึ่งครั้ง', 'duplicate');
        }
        $row = $this->finalize($survey, $this->key($survey->id, 'anonymous', $token), null, $answers, $ipHash);
        return ['status' => 'done', 'response' => $row, 'cookie' => $token];
    }

    /** $defer = true (direct submissions): the verification mail is sent after the response; envelopes keep the synchronous send, their handler retries on 'mail_failed'. */
    private function submitEmail(object $survey, array $answers, array $input, string $ipHash, string $wide = '', bool $defer = false): array
    {
        $name = self::normaliseName(is_scalar($input['name'] ?? null) ? (string) $input['name'] : '');
        $email = self::normaliseEmail(is_scalar($input['email'] ?? null) ? (string) $input['email'] : '');
        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) $errors['name'] = 'กรุณากรอกชื่อ-สกุล (2-160 ตัวอักษร)';
        if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'รูปแบบอีเมลไม่ถูกต้อง';
        if ($errors) throw ValidationException::withMessages($errors);
        $key = $this->key($survey->id, 'name_email', self::emailIdentity($email));
        // Every request counts against the limiters BEFORE the duplicate check, and a duplicate gets the same answer as a fresh
        // address ("link sent"), so nobody can probe which mailboxes already took part.
        $limiter = 'srv-mail:'.$key;
        if (RateLimiter::tooManyAttempts($limiter, 3)) throw new SurveyRejected(429, 'ส่งอีเมลยืนยันบ่อยเกินไป กรุณารอ 10 นาทีแล้วลองใหม่', 'mail_limit');
        // Ceilings that do not depend on the address typed: one network and the whole survey cannot turn the school SMTP into a spam cannon.
        $ipLimiter = 'srv-mail-ip:'.$survey->id.':'.$ipHash;
        if (RateLimiter::tooManyAttempts($ipLimiter, self::MAIL_PER_IP_HOUR)) throw new SurveyRejected(429, 'ส่งอีเมลยืนยันจากเครือข่ายนี้บ่อยเกินไป กรุณาลองใหม่ภายหลัง', 'mail_ip_limit');
        $allLimiter = 'srv-mail-all:'.$survey->id;
        if (RateLimiter::tooManyAttempts($allLimiter, self::MAIL_PER_SURVEY_MINUTE)) throw new SurveyRejected(429, 'ระบบกำลังส่งอีเมลยืนยันจำนวนมาก กรุณาลองใหม่ในอีกสักครู่', 'mail_survey_limit');
        $hourLimiter = 'srv-mail-hour-all';
        $hourMax = max(1, (int) config('saan.mail.max_per_hour', self::MAIL_PER_HOUR_ALL));
        if (RateLimiter::tooManyAttempts($hourLimiter, $hourMax)) throw new SurveyRejected(429, 'ระบบกำลังส่งอีเมลยืนยันจำนวนมาก กรุณาลองใหม่ภายหลัง', 'mail_hour_limit');
        // One network (/48) may take only a share of the host-wide hourly budget, across all surveys, so a single client cannot use it all up for everybody.
        $hourNet = 'srv-mail-hour-net:'.($wide !== '' ? $wide : $ipHash);
        if (RateLimiter::tooManyAttempts($hourNet, max(5, intdiv($hourMax, self::MAIL_HOUR_NET_SHARE)))) throw new SurveyRejected(429, 'ส่งอีเมลยืนยันจากเครือข่ายนี้บ่อยเกินไป กรุณาลองใหม่ภายหลัง', 'mail_ip_limit');
        RateLimiter::hit($limiter, 600);
        RateLimiter::hit($ipLimiter, 3600);
        RateLimiter::hit($allLimiter, 60);

        if (DB::table('srv_responses')->where('survey_id', $survey->id)->whereIn('respondent_key', $this->keys($survey->id, 'name_email', self::emailIdentity($email)))->exists()) {
            return ['status' => 'pending', 'response' => null, 'cookie' => null]; // already answered: no mail, same reply as a fresh address
        }
        RateLimiter::hit($hourLimiter, 3600); // only real mails count against the host quota
        RateLimiter::hit($hourNet, 3600);

        DB::table('srv_pending')->where('survey_id', $survey->id)->where('expires_at', '<', now())->delete();
        $nonce = bin2hex(random_bytes(16));
        try {
            // One atomic statement: a double click or two tabs cannot race a delete + insert into a unique violation or a gap-lock deadlock.
            // The nonce changes on every upsert, so a link mailed before the content was replaced no longer confirms the new content.
            DB::table('srv_pending')->upsert([[
                'survey_id' => $survey->id, 'respondent_key' => $key, 'display_name' => $name, 'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
                'expires_at' => now()->addHours(self::VERIFY_HOURS), 'created_at' => now(), 'response_id' => null, 'nonce' => $nonce,
            ]], ['survey_id', 'respondent_key'], ['display_name', 'answers', 'expires_at', 'created_at', 'response_id', 'nonce']);
            $pendingId = (int) DB::table('srv_pending')->where('survey_id', $survey->id)->where('respondent_key', $key)->value('id');
        } catch (QueryException $e) {
            if (self::isUniqueViolation($e) || self::isLockConflict($e)) throw new SurveyRejected(409, 'กำลังส่งลิงก์ยืนยัน กรุณาตรวจอีเมล', 'pending_race');
            throw $e;
        }
        // S5: in the internal edition the mail is sent by the internal side after the pull, so the link must open on the internal host
        // (the public host holds no pending row); every other edition keeps the /portal link.
        $url = URL::temporarySignedRoute(Edition::isInternal() ? 'surveys.confirm.internal' : 'surveys.confirm', now()->addHours(self::VERIFY_HOURS),['slug' => $survey->slug, 'pending' => $pendingId, 'n' => $nonce]);
        $send = fn () => Mail::to($email)->send(new SurveyVerifyMail($survey->title, $this->schoolName(), $url));
        if ($defer) {
            // Direct submission: the mail leaves after the response is out (same pattern as the portals' "forgot password", Contacts::deferSend), so a fresh
            // address answers as fast as one that already took part and the SMTP time cannot tell the two apart. A failure then looks exactly like a mail
            // that was sent; the pending row is removed. Runs once even when the app object serves several requests (tests).
            $done = false;
            app()->terminating(function () use ($send, $pendingId, &$done) {
                if ($done) return;
                $done = true;
                try {
                    $send();
                } catch (\Throwable $e) {
                    DB::table('srv_pending')->where('id', $pendingId)->delete();
                    report($e);
                }
            });
            return ['status' => 'pending', 'response' => null, 'cookie' => null];
        }
        try {
            $send();
        } catch (\Throwable $e) {
            DB::table('srv_pending')->where('id', $pendingId)->delete();
            report($e);
            throw new SurveyRejected(503, 'ส่งอีเมลยืนยันไม่สำเร็จ กรุณาลองใหม่ภายหลัง', 'mail_failed');
        }
        return ['status' => 'pending', 'response' => null, 'cookie' => null];
    }

    /** The roster row of an unused code (null for an unknown, empty or used one). Read only: nothing is counted and nothing is claimed. */
    public function unusedRosterRow(object $survey, mixed $code): ?object
    {
        $c = self::normaliseCode(is_scalar($code) ? (string) $code : '');
        if ($c === '' || strlen($c) > 60) return null;
        return DB::table('srv_roster')->where('survey_id', $survey->id)->whereIn('code_hash', $this->keys($survey->id, 'roster_code', $c))->whereNull('used_at')->first();
    }

    /** $refund = false (envelopes from the public side): a success does not give the per-session and per-network budget back, those keys come from hints the sender chose. */
    private function submitRoster(object $survey, array $answers, array $input, string $ipHash, string $sessionId = '', bool $refund = true, string $wide = ''): array
    {
        $code = self::normaliseCode(is_scalar($input['code'] ?? null) ? (string) $input['code'] : '');
        // Whole-survey lock (direct submissions only: envelopes carry their own survey ceiling in InboxHandler): too many wrong codes from everybody
        // together means somebody is guessing, so code entry stops for a while and the admin sees the flag on the roster screen.
        $surveyLimiter = 'srv-code-survey:'.$survey->id;
        $ceiling = self::ROSTER_WRONG_MIN;
        if ($refund) {
            if (self::codeLocked((int) $survey->id)) throw new SurveyRejected(429, 'ระบบหยุดรับรหัสของแบบสอบถามนี้ชั่วคราว เพราะมีการกรอกรหัสผิดจำนวนมาก กรุณาแจ้งครูหรือเจ้าหน้าที่', 'code_locked');
            $ceiling = max(self::ROSTER_WRONG_MIN, (int) ceil(1.5 * (int) DB::table('srv_roster')->where('survey_id', $survey->id)->count()));
            if (RateLimiter::tooManyAttempts($surveyLimiter, $ceiling)) {
                \Illuminate\Support\Facades\Cache::put('srv-code-lock:'.$survey->id, time(), self::ROSTER_LOCK_SECONDS);
                RateLimiter::clear($surveyLimiter);
                \Illuminate\Support\Facades\Log::warning('survey roster code entry locked: too many wrong codes', ['survey_id' => $survey->id]);
                throw new SurveyRejected(429, 'ระบบหยุดรับรหัสของแบบสอบถามนี้ชั่วคราว เพราะมีการกรอกรหัสผิดจำนวนมาก กรุณาแจ้งครูหรือเจ้าหน้าที่', 'code_locked');
            }
        }
        $wideLimiter = $wide !== '' ? 'srv-code-wide:'.$survey->id.':'.$wide : null;
        if ($wideLimiter && RateLimiter::tooManyAttempts($wideLimiter, self::ROSTER_PER_WIDE)) throw new SurveyRejected(429, 'กรอกรหัสผิดหลายครั้ง กรุณารอ 10 นาทีแล้วลองใหม่', 'code_limit');
        // The 10-minute ceilings alone would let ONE network reach the survey-wide lock within the hour (3 x 200) and shut everybody else out: its hourly
        // share stays below half of that ceiling (48 %, so it grows with a large roster like the ceiling does; a whole school behind one address keeps room).
        $wideHour = $refund && $wide !== '' ? 'srv-code-wide-hour:'.$survey->id.':'.$wide : null;
        if ($wideHour && RateLimiter::tooManyAttempts($wideHour, max(self::ROSTER_PER_WIDE_HOUR, intdiv($ceiling * 48, 100)))) throw new SurveyRejected(429, 'กรอกรหัสผิดจากเครือข่ายนี้หลายครั้งเกินไป กรุณาลองใหม่ภายหลัง', 'code_limit');
        // A typo from one student must not lock a whole computer lab: wrong codes count per browser session, with a higher per-network ceiling.
        $sessionLimiter = 'srv-code:'.$survey->id.':'.$ipHash.':'.$sessionId;
        $ipLimiter = 'srv-code-ip:'.$survey->id.':'.$ipHash;
        if (RateLimiter::tooManyAttempts($sessionLimiter, self::ROSTER_PER_SESSION) || RateLimiter::tooManyAttempts($ipLimiter, self::ROSTER_PER_IP)) {
            throw new SurveyRejected(429, 'กรอกรหัสผิดหลายครั้ง กรุณารอ 10 นาทีแล้วลองใหม่', 'code_limit');
        }
        $key = $this->key($survey->id, 'roster_code', $code);
        // Every submitted code counts (unknown, already used or valid) so a used code cannot be probed for free; a success gives the budget back.
        RateLimiter::hit($sessionLimiter, 600);
        RateLimiter::hit($ipLimiter, 600);
        if ($wideLimiter) RateLimiter::hit($wideLimiter, 600);
        if ($wideHour) RateLimiter::hit($wideHour, 3600);
        if ($refund) RateLimiter::hit($surveyLimiter, 3600);
        $row = $code === '' ? null : DB::table('srv_roster')->where('survey_id', $survey->id)->whereIn('code_hash', $this->keys($survey->id, 'roster_code', $code))->first();
        if ($row) $key = (string) $row->code_hash; // keep the key the roster was imported with (it may predate a rotation)
        if (!$row) throw ValidationException::withMessages(['code' => 'ไม่พบรหัสนี้ในรายชื่อ']);
        $response = $this->finalize($survey, $key, $row->display_name, $answers, $ipHash, (int) $row->id);
        if ($refund) {
            RateLimiter::clear($sessionLimiter);
            RateLimiter::decrement($ipLimiter, 600);
            if ($wideLimiter) RateLimiter::decrement($wideLimiter, 600);
            if ($wideHour) RateLimiter::decrement($wideHour, 3600);
            RateLimiter::decrement($surveyLimiter, 3600);
        }
        return ['status' => 'done', 'response' => $response, 'cookie' => null];
    }

    /** Pending row behind a signed link, or a 410. A row that was already used is returned too (its response_id is set). */
    public function pending(object $survey, int $pendingId, ?string $nonce = null): object
    {
        $pending = DB::table('srv_pending')->where('survey_id', $survey->id)->where('id', $pendingId)->first();
        if (!$pending || $pending->expires_at < now()->format('Y-m-d H:i:s')) {
            throw new SurveyRejected(410, 'ลิงก์ยืนยันนี้หมดอายุแล้ว กรุณากรอกแบบสอบถามใหม่', 'link_expired');
        }
        // The link carries the nonce of the content it was mailed for; a later re-submit replaces the nonce and kills older links.
        // Rows created before the nonce column existed have none and keep working with the links already mailed.
        if (($pending->nonce ?? null) !== null && !hash_equals((string) $pending->nonce, (string) $nonce)) {
            throw new SurveyRejected(410, 'ลิงก์ยืนยันนี้ถูกแทนที่ด้วยลิงก์ที่ใหม่กว่าแล้ว หากท่านไม่ได้ส่งใหม่เอง กรุณากรอกแบบสอบถามใหม่', 'link_replaced');
        }
        return $pending;
    }

    /** Name and answers held in a pending row, labelled by question, for the confirm page (only the mailbox owner sees it). @return list<array{0:string,1:string}> */
    public function pendingSummary(object $survey, object $pending): array
    {
        $answers = json_decode((string) $pending->answers, true);
        $answers = is_array($answers) ? $answers : [];
        $rows = [];
        foreach ($this->questions($survey) as $q) {
            if (!array_key_exists($q['key'], $answers)) continue;
            $v = $answers[$q['key']];
            $rows[] = [(string) $q['label'], is_array($v) ? implode(', ', array_map('strval', $v)) : (string) $v];
        }
        return $rows;
    }

    public function responseById(int $id): ?object
    {
        return DB::table('srv_responses')->where('id', $id)->first();
    }

    /**
     * Called from the POST behind the signed e-mail link (a GET only shows the button, so mail scanners that prefetch links
     * cannot burn it). Idempotent: a repeat returns the response created the first time.
     */
    public function confirm(object $survey, int $pendingId, ?string $ip, ?string $nonce = null): object
    {
        $this->pending($survey, $pendingId, $nonce);
        return DB::transaction(function () use ($survey, $pendingId, $ip, $nonce) {
            $pending = DB::table('srv_pending')->where('survey_id', $survey->id)->where('id', $pendingId)->lockForUpdate()->first();
            if (!$pending) throw new SurveyRejected(410, 'ลิงก์ยืนยันนี้หมดอายุแล้ว กรุณากรอกแบบสอบถามใหม่', 'link_expired');
            if (($pending->nonce ?? null) !== null && !hash_equals((string) $pending->nonce, (string) $nonce)) { // replaced between the check above and the lock
                throw new SurveyRejected(410, 'ลิงก์ยืนยันนี้ถูกแทนที่ด้วยลิงก์ที่ใหม่กว่าแล้ว หากท่านไม่ได้ส่งใหม่เอง กรุณากรอกแบบสอบถามใหม่', 'link_replaced');
            }
            if ($pending->response_id !== null && ($done = $this->responseById((int) $pending->response_id))) return $done;
            if ($survey->identity_mode !== 'name_email') throw new SurveyRejected(410, 'แบบสอบถามนี้เปลี่ยนวิธีระบุตัวตนแล้ว กรุณากรอกใหม่', 'mode_changed');
            $s = $this->find((int) $survey->id);
            try { $answers = $this->validateAnswers($this->questions($s), json_decode((string) $pending->answers, true) ?: []); }
            catch (ValidationException) { throw new SurveyRejected(410, 'แบบสอบถามถูกแก้ไขหลังจากท่านส่งคำตอบ กรุณากรอกใหม่', 'questions_changed'); }
            $row = $this->finalize($s, $pending->respondent_key, $pending->display_name, $answers, $this->ipHash($ip, (int) $s->id));
            DB::table('srv_pending')->where('id', $pending->id)->update(['response_id' => $row->id]);
            return $row;
        });
    }

    /**
     * The single place a response is counted. One transaction: lock the survey row (serialises max_responses and
     * certificate numbering on MariaDB), re-check availability, claim the roster code, insert, number the certificate.
     */
    public function finalize(object $survey, string $respondentKey, ?string $name, array $answers, string $ipHash, ?int $rosterId = null): object
    {
        // 3 attempts: Laravel retries the whole transaction on a deadlock instead of surfacing a 500.
        return DB::transaction(function () use ($survey, $respondentKey, $name, $answers, $ipHash, $rosterId) {
            $s = DB::table('srv_surveys')->where('school_id', $this->school())->where('id', $survey->id)->lockForUpdate()->first() ?? abort(404);
            $this->rejectIfClosed($s, true);
            $duplicate = function () use ($s, $respondentKey, $rosterId) {
                // Never hand back the certificate number or link here: roster codes are guessable (often student numbers), so a
                // used code must not open someone's certificate. The certificate link is only shown right after a successful insert.
                return new SurveyRejected(409, $rosterId === null ? 'ท่านตอบแบบสอบถามนี้แล้ว หนึ่งคนตอบได้หนึ่งครั้ง' : 'รหัสนี้ใช้ตอบแบบสอบถามนี้ไปแล้ว หากท่านยังไม่ได้ตอบ กรุณาแจ้งครูหรือเจ้าหน้าที่', 'duplicate');
            };
            if (DB::table('srv_responses')->where('survey_id', $s->id)->where('respondent_key', $respondentKey)->exists()) throw $duplicate();
            if ($rosterId !== null) {
                $claimed = DB::table('srv_roster')->where('id', $rosterId)->where('survey_id', $s->id)->whereNull('used_at')->update(['used_at' => now()]);
                if ($claimed === 0) throw new SurveyRejected(409, 'รหัสนี้ถูกใช้แล้ว', 'code_used');
            }
            $questions = $this->questions($s);
            $score = $this->score($questions, $answers);
            try {
                $id = (int) DB::table('srv_responses')->insertGetId([
                    'survey_id' => $s->id, 'respondent_key' => $respondentKey, 'display_name' => $name, 'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
                    'score' => $score, 'certificate_no' => null, 'created_at' => now(), 'ip_hash' => $ipHash,
                ]);
            } catch (QueryException $e) {
                if (self::isUniqueViolation($e)) throw $duplicate();
                throw $e;
            }
            $cert = $this->certificate($s);
            $minScore = $cert['min_score'] ?? null;
            if ($cert && $name !== null && ($minScore === null || ($score !== null && $score >= (int) $minScore))) {
                $no = $this->nextCertificateNo();
                try {
                    DB::table('srv_responses')->where('id', $id)->update(['certificate_no' => $no, 'certificate_snapshot' => json_encode($this->snapshot($s, $cert, $name, $no), JSON_UNESCAPED_UNICODE)]);
                } catch (QueryException $e) {
                    if (self::isUniqueViolation($e)) throw new SurveyRejected(503, 'ออกเลขเกียรติบัตรไม่สำเร็จ กรุณาลองใหม่', 'cert_no');
                    throw $e;
                }
            }
            return DB::table('srv_responses')->where('id', $id)->first();
        }, 3);
    }

    /** Wording frozen at issue time, so later edits of the survey or its certificate never rewrite certificates already handed out. */
    private function snapshot(object $survey, array $cert, string $name, string $no): array
    {
        $when = now('Asia/Bangkok')->locale('th');
        $date = $when->translatedFormat('j F').' '.($when->year + 543);
        $school = $this->schoolName();
        return [
            'title' => (string) ($cert['title'] ?? 'เกียรติบัตร'),
            'body' => strtr((string) ($cert['body_template'] ?? ''), ['{name}' => $name, '{survey}' => $survey->title, '{date}' => $date, '{no}' => $no, '{school}' => $school]),
            'signer_name' => (string) ($cert['signer_name'] ?? ''), 'signer_title' => (string) ($cert['signer_title'] ?? ''),
            'survey_title' => $survey->title, 'school_name' => $school, 'date' => $date,
        ];
    }

    // ---- certificate numbers ------------------------------------------------------------------

    public function shortCode(): string
    {
        $short = '';
        try { $short = (string) Settings::get('school.short_code', ''); } catch (\Throwable) {}
        $short = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $short) ?? '');
        return $short !== '' ? substr($short, 0, 12) : (string) $this->school();
    }

    public static function buddhistYear(): int
    {
        return (int) now('Asia/Bangkok')->year + 543;
    }

    /** Must run inside the caller's transaction: the counter row stays locked until commit, so numbers never repeat. */
    public function nextCertificateNo(): string
    {
        $school = $this->school();
        $year = self::buddhistYear();
        // Ensure the row exists first (INSERT IGNORE), then lock-and-bump it: no UPDATE on a missing row, hence no gap-lock deadlock.
        DB::table('srv_counters')->insertOrIgnore(['school_id' => $school, 'year' => $year, 'last' => 0]);
        DB::table('srv_counters')->where('school_id', $school)->where('year', $year)->update(['last' => DB::raw('last + 1')]);
        $seq = (int) DB::table('srv_counters')->where('school_id', $school)->where('year', $year)->value('last');
        return sprintf('%s-%d-%06d', $this->shortCode(), $year, $seq);
    }

    /** Certificate row joined to its survey (school scoped), or null. Number format is checked before touching the DB. */
    public function certificateRow(string $no): ?object
    {
        $no = strtoupper(trim($no));
        if (!preg_match('/^[A-Z0-9]{1,12}-\d{4}-\d{6,}$/D', $no)) return null;
        return DB::table('srv_responses')->join('srv_surveys', 'srv_surveys.id', '=', 'srv_responses.survey_id')
            ->where('srv_surveys.school_id', $this->school())->where('srv_responses.certificate_no', $no)
            ->first(['srv_responses.certificate_no', 'srv_responses.display_name', 'srv_responses.created_at', 'srv_responses.certificate_snapshot', 'srv_surveys.title as survey_title', 'srv_surveys.certificate as certificate_conf']);
    }

    private ?string $schoolNameCache = null;

    public function schoolName(): string
    {
        if ($this->schoolNameCache !== null) return $this->schoolNameCache;
        $theme = app(\App\Domain\CmsPublishing::class)->theme($this->school())['published'] ?? [];
        return $this->schoolNameCache = (string) ($theme['school_name'] ?? 'โรงเรียน');
    }
}
