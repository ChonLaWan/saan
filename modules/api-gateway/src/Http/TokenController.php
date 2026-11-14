<?php

namespace Saan\Modules\ApiGateway\Http;

use App\Support\Saan\NetKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Saan\Modules\ApiGateway\Gateway;
use Saan\Modules\ApiGateway\Net;
use Saan\Modules\ApiGateway\Scopes;

/**
 * POST /api/v1/token and POST /oauth/token (same handler).
 * - grant_type=client_credentials: client_secret_basic (Authorization: Basic) or client_secret_post.
 * - grant_type=authorization_code: code + redirect_uri + code_verifier (PKCE S256) from the student sign-in.
 * Always authenticated by the client secret; error bodies follow RFC 6749 section 5.2.
 */
class TokenController
{
    private const IP_PER_MINUTE = 30;
    private const BAD_SECRET_PER_MINUTE = 10;
    private const ISSUE_PER_MINUTE = 30;

    public function token(Request $r): JsonResponse
    {
        $t0 = microtime(true);
        $net = NetKey::rate($r->ip());
        $ipKey = 'api-token-ip:'.$net;
        if (RateLimiter::tooManyAttempts($ipKey, self::IP_PER_MINUTE)) return $this->finish($r, null, $t0, $this->limited($ipKey));
        // only failed client authentication counts against the per-IP budget: one app server legitimately exchanges many codes

        [$clientId, $secret] = $this->credentials($r);
        if ($clientId === '' || $secret === '') {
            RateLimiter::hit($ipKey, 60);
            return $this->finish($r, null, $t0, $this->invalidClient());
        }
        $failKey = 'api-token-fail:'.sha1($clientId.'|'.$net);
        if (RateLimiter::tooManyAttempts($failKey, self::BAD_SECRET_PER_MINUTE)) return $this->finish($r, null, $t0, $this->limited($failKey));

        $client = Gateway::client($clientId);
        if (!Gateway::secretMatches($client, $secret) || !$client->is_active) {
            RateLimiter::hit($failKey, 60);
            RateLimiter::hit($ipKey, 60);
            return $this->finish($r, $client?->id, $t0, $this->invalidClient());
        }
        if (!Net::ipAllowed(Gateway::json($client->allowed_ips), (string) $r->ip())) {
            return $this->finish($r, $client->id, $t0, Gateway::error('access_denied', 'ที่อยู่ IP นี้ไม่ได้รับอนุญาต', 403));
        }
        $grant = (string) $r->input('grant_type', '');
        // One budget per grant type: a class signing in through SSO must not starve the app server's client_credentials sync.
        $issueKey = 'api-token-client:'.$client->id.':'.(in_array($grant, ['authorization_code', 'client_credentials'], true) ? $grant : 'other');
        // SSO exchanges follow the client's own budget (a class starting a lesson), machine tokens stay tightly capped
        $issueLimit = $grant === 'authorization_code' ? max(60, (int) $client->rate_per_minute) : self::ISSUE_PER_MINUTE;
        if (RateLimiter::tooManyAttempts($issueKey, $issueLimit)) return $this->finish($r, $client->id, $t0, $this->limited($issueKey));
        RateLimiter::hit($issueKey, 60);

        $response = match ($grant) {
            'client_credentials' => $this->clientCredentials($r, $client),
            'authorization_code' => $this->authorizationCode($r, $client),
            default => Gateway::error('unsupported_grant_type', 'รองรับ client_credentials และ authorization_code เท่านั้น', 400),
        };
        return $this->finish($r, $client->id, $t0, $response);
    }

    private function clientCredentials(Request $r, object $client): JsonResponse
    {
        $granted = array_values(array_diff(Gateway::clientScopes($client), [Scopes::SSO]));
        $asked = preg_split('/\s+/', trim((string) $r->input('scope', '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $scopes = $asked ?: $granted;
        if (array_diff($scopes, $granted)) return Gateway::error('invalid_scope', 'ขอ scope ที่ไคลเอนต์นี้ไม่ได้รับสิทธิ์', 400);
        if (!$scopes) return Gateway::error('invalid_scope', 'ไคลเอนต์นี้ยังไม่มี scope ข้อมูล', 400);
        return $this->bearer(...Gateway::issueToken($client, $scopes), scopes: $scopes);
    }

    private function authorizationCode(Request $r, object $client): JsonResponse
    {
        $bad = fn (string $why) => Gateway::error('invalid_grant', $why, 400);
        $code = (string) $r->input('code', '');
        $verifier = (string) $r->input('code_verifier', '');
        if (!in_array(Scopes::SSO, Gateway::clientScopes($client), true)) return Gateway::error('unauthorized_client', 'ไคลเอนต์นี้ไม่ได้รับสิทธิ์ให้นักเรียนเข้าสู่ระบบ', 400);
        if (!preg_match('/^[a-f0-9]{64}$/D', $code) || !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/D', $verifier)) return $bad('code หรือ code_verifier ไม่ถูกต้อง');

        $row = DB::table('api_sso_codes')->where('code_hash', hash('sha256', $code))->where('client_id', $client->id)->first();
        if (!$row) return $bad('code ไม่ถูกต้อง');
        // Claim first, then judge: any attempt (even with a wrong verifier) spends the code, so it cannot be guessed against.
        $claimed = DB::table('api_sso_codes')->where('id', $row->id)->whereNull('used_at')->update(['used_at' => now()]);
        if ($claimed === 0) {
            Gateway::revokeTokens((int) $client->id, (int) $row->id); // replay: whatever this code produced is no longer trusted
            return $bad('code ถูกใช้ไปแล้ว');
        }
        if (strtotime((string) $row->expires_at) <= time()) return $bad('code หมดอายุ');
        if (!hash_equals((string) $row->redirect_uri, (string) $r->input('redirect_uri', ''))) return $bad('redirect_uri ไม่ตรงกับตอนขอ code');
        if (!hash_equals((string) $row->code_challenge, Net::b64url(hash('sha256', $verifier, true)))) return $bad('code_verifier ไม่ตรงกับ code_challenge');
        $exists = $row->user_kind === 'student' && DB::table('students')->where('school_id', Gateway::school())->where('id', $row->subject_id)->exists();
        if (!$exists) return $bad('ไม่พบผู้ใช้');

        [$plain, $ttl] = Gateway::issueToken($client, [Scopes::ME_READ], (string) $row->user_kind, (int) $row->subject_id, (int) $row->id);
        return $this->bearer($plain, $ttl, scopes: [Scopes::ME_READ]);
    }

    private function bearer(string $plain, int $ttl, array $scopes): JsonResponse
    {
        return response()->json(['access_token' => $plain, 'token_type' => 'Bearer', 'expires_in' => $ttl, 'scope' => implode(' ', $scopes)],
            200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /** @return array{0:string,1:string} client id and secret, Basic header first, then the request body. */
    private function credentials(Request $r): array
    {
        $h = (string) $r->header('Authorization', '');
        if (preg_match('/^Basic\s+(\S+)$/i', $h, $m)) {
            $pair = base64_decode($m[1], true);
            if ($pair !== false && str_contains($pair, ':')) {
                [$id, $secret] = explode(':', $pair, 2);
                return [trim(urldecode($id)), urldecode($secret)];
            }
            return ['', ''];
        }
        return [trim((string) $r->input('client_id', '')), (string) $r->input('client_secret', '')];
    }

    private function invalidClient(): JsonResponse
    {
        return Gateway::error('invalid_client', 'client_id หรือ client_secret ไม่ถูกต้อง', 401, ['WWW-Authenticate' => 'Basic realm="saan-api"']);
    }

    private function limited(string $key): JsonResponse
    {
        $wait = max(1, RateLimiter::availableIn($key));
        return Gateway::error('rate_limited', 'ลองใหม่ถี่เกินกำหนด กรุณารอ '.$wait.' วินาที', 429, ['Retry-After' => $wait]);
    }

    private function finish(Request $r, ?int $clientId, float $t0, JsonResponse $response): JsonResponse
    {
        // a rate-limited request writes no row (same rule as ApiAuth): a flood must not turn into a flood of log rows
        if ($response->getStatusCode() !== 429) Gateway::log($clientId, $r, $response->getStatusCode(), $t0, $r->path());
        return $response;
    }
}
