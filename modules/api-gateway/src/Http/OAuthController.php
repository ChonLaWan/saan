<?php

namespace Saan\Modules\ApiGateway\Http;

use App\Support\Saan\NetKey;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Saan\Modules\ApiGateway\Gateway;
use Saan\Modules\ApiGateway\Net;
use Saan\Modules\ApiGateway\Pins;
use Saan\Modules\ApiGateway\Scopes;

/**
 * Student sign-in for learning apps: OAuth2 authorization code + PKCE (S256 only).
 * GET/POST /oauth/authorize. The student types the student code and the PIN given by the homeroom teacher.
 * A bad client or redirect_uri is shown as an error page and never redirected to; other problems redirect with ?error=.
 * The page is a normal web form, so CSRF protection stays on for it (only the machine endpoints are exempt).
 */
class OAuthController
{
    public const LOGIN_PER_IP_5_MIN = 300;
    public const LOGIN_PER_CODE_5_MIN = 10;
    public const LOGIN_FAILS_PER_CLIENT_5_MIN = 200;
    public const LOGIN_FAILS_PER_SCHOOL_5_MIN = 600;
    /** Once a client app or the school is under a failure flood, only networks that already failed this many times are refused; a network with a clean record can still sign in. */
    public const HOT_NETWORK_MIN_FAILS = 3;
    public const MAX_STATE_BYTES = 1024;
    private const MESSAGE = 'รหัสนักเรียนหรือ PIN ไม่ถูกต้อง หรือบัญชีถูกพักชั่วคราว กรุณาลองใหม่หรือแจ้งครูประจำชั้น';

    public function show(Request $r)
    {
        $p = $this->params($r);
        if ($p instanceof \Illuminate\Http\Response || $p instanceof \Illuminate\Http\RedirectResponse) return $p;
        return $this->page($p, null, 200);
    }

    public function submit(Request $r)
    {
        $p = $this->params($r);
        if ($p instanceof \Illuminate\Http\Response || $p instanceof \Illuminate\Http\RedirectResponse) return $p;

        // Two layers: a high ceiling per IP (a whole school can share one NAT address) and a tight one per IP + student code.
        // The per-student PIN lockout in Pins::attempt() is the real brute-force guard.
        // Keys use the /64 prefix for IPv6 (one subscriber owns 2^64 addresses). Two more ceilings do not depend on the address at all:
        // failed sign-ins per client app and per school, so rotating networks cannot scale a PIN guessing run.
        $net = NetKey::rate($r->ip());
        $ipKey = 'oauth-login-ip:'.$net;
        $clientKey = 'oauth-login-client:'.$p['client']->id;
        $schoolKey = 'oauth-login-school:'.Gateway::school();
        $tooMany = 'ลองเข้าสู่ระบบถี่เกินไป กรุณารอสักครู่แล้วลองใหม่';
        $failKey = 'oauth-login-netfail:'.$net;
        if (RateLimiter::tooManyAttempts($ipKey, self::LOGIN_PER_IP_5_MIN)) return $this->page($p, $tooMany, 429);
        // The client/school ceilings never block a clean network (a flood from elsewhere must not stop students with the right PIN):
        // they only tighten the rules for networks that have already failed repeatedly.
        // A hot network is never refused up front: students behind a shared NAT with the right PIN must still get in (a flood must not become a denial of service).
        // Its attempts are slowed down, a wrong one is answered 429, and it no longer feeds the client/school counters (so a flood cannot keep itself "hot").
        $hot = (RateLimiter::tooManyAttempts($clientKey, self::LOGIN_FAILS_PER_CLIENT_5_MIN) || RateLimiter::tooManyAttempts($schoolKey, self::LOGIN_FAILS_PER_SCHOOL_5_MIN))
            && RateLimiter::attempts($failKey) >= self::HOT_NETWORK_MIN_FAILS;
        // No server-side sleep (it would hold a PHP-FPM worker per request). Instead a hot network gets ONE bcrypt check in flight at a time:
        // a parallel request from the same network is answered 429 at once, so a flood cannot exhaust the worker pool.
        $slot = null;
        if ($hot) {
            try {
                $slot = \Illuminate\Support\Facades\Cache::lock('oauth-hot-slot:'.sha1($net), 5);
                if (!$slot->get()) {
                    Gateway::log((int) $p['client']->id, $r, 429, microtime(true), 'oauth/authorize');
                    return $this->page($p, $tooMany, 429);
                }
            } catch (\Throwable $e) {
                $slot = null; // cache store without locks: fall back to the other limits
            }
        }
        try {
            $input = $r->validate(['student_code' => 'required|string|max:40', 'pin' => 'required|string|max:200']);
            $codeKey = 'oauth-login:'.sha1($net.'|'.Net::key($input['student_code']));
            if (RateLimiter::tooManyAttempts($codeKey, self::LOGIN_PER_CODE_5_MIN)) return $this->page($p, $tooMany, 429);
            // one secret store with the student portal: a PIN or the password the student chose there, checked by the same counters and lock
            $student = Pins::attempt($input['student_code'], $input['pin'], $net, true);
        } finally {
            if ($slot) { try { $slot->release(); } catch (\Throwable $e) {} }
        }
        if (!$student) {
            RateLimiter::hit($ipKey, 300);
            RateLimiter::hit($failKey, 300);
            RateLimiter::hit($codeKey, 300);
            if (!$hot) {
                RateLimiter::hit($clientKey, 300);
                RateLimiter::hit($schoolKey, 300);
            }
            Gateway::log((int) $p['client']->id, $r, $hot ? 429 : 401, microtime(true), 'oauth/authorize');
            return $hot ? $this->page($p, $tooMany, 429) : $this->page($p, self::MESSAGE, 401);
        }

        // the temporary secret printed on the slip works for nothing but changing it (the student portal), exactly as on /student; without the portal there is nowhere to change it, so the flag is not enforced
        if (\App\Support\Saan\Edition::serves('student') && app(\App\Support\Modules\ModuleRegistry::class)->active('student-portal') && Schema::hasColumn('api_student_pins', 'must_change') && DB::table('api_student_pins')->where('school_id', Gateway::school())->where('student_id', $student->id)->value('must_change')) {
            Gateway::log((int) $p['client']->id, $r, 403, microtime(true), 'oauth/authorize');
            return $this->page($p, 'รหัสชั่วคราวที่ครูให้ต้องเปลี่ยนก่อนใช้งาน กรุณาเข้าหน้านักเรียนของโรงเรียนเพื่อตั้งรหัสผ่านของตัวเอง แล้วกลับมาเข้าแอปอีกครั้ง', 403);
        }

        $code = bin2hex(random_bytes(32));
        DB::table('api_sso_codes')->insert([
            'client_id' => $p['client']->id, 'code_hash' => hash('sha256', $code), 'user_kind' => 'student', 'subject_id' => $student->id,
            'redirect_uri' => $p['redirect_uri'], 'code_challenge' => $p['code_challenge'],
            'expires_at' => CarbonImmutable::now()->addSeconds(Gateway::CODE_TTL), 'created_at' => now(),
        ]);
        Gateway::log((int) $p['client']->id, $r, 302, microtime(true), 'oauth/authorize');
        return $this->back($p, ['code' => $code]);
    }

    /** @return array|\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse validated parameters, or the response to send */
    private function params(Request $r)
    {
        $clientId = (string) $r->input('client_id', '');
        $uri = (string) $r->input('redirect_uri', '');
        $client = $clientId !== '' && strlen($clientId) <= 40 ? Gateway::client($clientId) : null;
        $uris = $client ? Gateway::json($client->redirect_uris) : [];
        if (!$client || !$client->is_active || !in_array(Scopes::SSO, Gateway::clientScopes($client), true) || !in_array($uri, $uris, true)) {
            return response()->view('api-gateway::authorize-error', ['message' => 'ลิงก์เข้าสู่ระบบไม่ถูกต้อง กรุณากลับไปเริ่มจากแอปอีกครั้ง'], 400);
        }
        $p = ['client' => $client, 'redirect_uri' => $uri, 'state' => (string) $r->input('state', ''),
            'code_challenge' => (string) $r->input('code_challenge', '')];
        // never truncate state: the client compares it byte for byte; an oversized one is refused (RFC 6749 4.1.2.1 invalid_request)
        if (strlen($p['state']) > self::MAX_STATE_BYTES) {
            $p['state'] = '';
            return $this->back($p, ['error' => 'invalid_request', 'error_description' => 'state longer than '.self::MAX_STATE_BYTES.' bytes']);
        }
        if ($r->input('response_type') !== 'code') return $this->back($p, ['error' => 'unsupported_response_type']);
        if ($r->input('code_challenge_method') !== 'S256' || !preg_match('/^[A-Za-z0-9\-_]{43,128}$/D', $p['code_challenge'])) {
            return $this->back($p, ['error' => 'invalid_request', 'error_description' => 'PKCE S256 required']);
        }
        return $p;
    }

    private function back(array $p, array $query)
    {
        if ($p['state'] !== '') $query['state'] = $p['state'];
        $sep = str_contains($p['redirect_uri'], '?') ? '&' : '?';
        return redirect()->away($p['redirect_uri'].$sep.http_build_query($query));
    }

    private function page(array $p, ?string $error, int $status)
    {
        return response()->view('api-gateway::authorize', [
            'client' => $p['client']->name, 'error' => $error,
            'fields' => ['client_id' => $p['client']->client_id, 'redirect_uri' => $p['redirect_uri'], 'state' => $p['state'],
                'code_challenge' => $p['code_challenge'], 'code_challenge_method' => 'S256', 'response_type' => 'code'],
        ], $status);
    }
}
