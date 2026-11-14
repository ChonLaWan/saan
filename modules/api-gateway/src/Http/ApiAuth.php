<?php

namespace Saan\Modules\ApiGateway\Http;

use App\Support\Saan\NetKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Saan\Modules\ApiGateway\Gateway;
use Saan\Modules\ApiGateway\Net;
use Saan\Modules\ApiGateway\Scopes;

/**
 * Bearer authentication for /api/v1/*. Usage: ApiAuth::class.':students:read:basic' (first colon splits the class from the scope).
 * Order: token -> client active and in this school -> IP allowlist -> per-client rate limit -> scope. Every outcome is logged.
 * Request attributes set for controllers: api_client (row), api_token (row), api_scopes (effective scopes).
 */
class ApiAuth
{
    private const ANON_PER_MINUTE = 60;

    public function handle(Request $request, Closure $next, string $scope)
    {
        $t0 = microtime(true);
        $ipKey = 'api-anon:'.NetKey::rate($request->ip());
        $bearer = $this->bearer($request);
        $token = $bearer === null ? null : DB::table('api_tokens as t')
            ->join('api_clients as c', 'c.id', '=', 't.client_id')
            ->where('t.token_hash', hash('sha256', $bearer))->where('c.school_id', Gateway::school())
            ->first(['t.id as token_id', 't.scopes as token_scopes', 't.user_kind', 't.subject_id', 't.expires_at', 't.revoked_at',
                'c.id', 'c.client_id', 'c.scopes', 'c.allowed_ips', 'c.rate_per_minute', 'c.is_active', 'c.last_used_at']);

        if (!$token || $token->revoked_at !== null || !$token->is_active || strtotime((string) $token->expires_at) <= time()) {
            if (RateLimiter::tooManyAttempts($ipKey, self::ANON_PER_MINUTE)) {
                // blocked callers are not logged: otherwise one IP could grow api_request_log without bound
                $resp = $this->limited($ipKey, self::ANON_PER_MINUTE);
                $resp->headers->set('Cache-Control', 'no-store');
                return $resp;
            }
            RateLimiter::hit($ipKey, 60);
            return $this->done($request, $token?->id, $t0, Gateway::error('invalid_token', 'โทเคนไม่ถูกต้อง หมดอายุ หรือถูกเพิกถอน', 401, ['WWW-Authenticate' => 'Bearer error="invalid_token"']));
        }

        if (!Net::ipAllowed(Gateway::json($token->allowed_ips), (string) $request->ip())) {
            return $this->done($request, $token->id, $t0, Gateway::error('access_denied', 'ที่อยู่ IP นี้ไม่ได้รับอนุญาต', 403));
        }

        $key = 'api-client:'.$token->id;
        $limit = max(1, (int) $token->rate_per_minute);
        if (RateLimiter::tooManyAttempts($key, $limit)) return $this->done($request, $token->id, $t0, $this->limited($key, $limit));
        RateLimiter::hit($key, 60);

        $scopes = Scopes::effective(Gateway::json($token->token_scopes), Gateway::json($token->scopes));
        if (!in_array($scope, $scopes, true)) {
            return $this->done($request, $token->id, $t0, Gateway::error('insufficient_scope', 'โทเคนนี้ไม่มีสิทธิ์ '.$scope, 403, ['WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="'.$scope.'"']), $limit, $key);
        }

        if ($token->last_used_at === null || strtotime((string) $token->last_used_at) < time() - 60) {
            DB::table('api_clients')->where('id', $token->id)->update(['last_used_at' => now()]);
        }
        $request->attributes->set('api_client', $token);
        $request->attributes->set('api_scopes', $scopes);
        $response = $next($request);
        return $this->done($request, $token->id, $t0, $response, $limit, $key);
    }

    private function bearer(Request $request): ?string
    {
        if (!preg_match('/^Bearer\s+(\S{20,200})$/i', (string) $request->header('Authorization', ''), $m)) return null;
        return $m[1];
    }

    private function limited(string $key, int $limit)
    {
        $wait = max(1, RateLimiter::availableIn($key));
        return Gateway::error('rate_limited', 'เรียกใช้ถี่เกินกำหนด กรุณารอ '.$wait.' วินาที', 429, ['Retry-After' => $wait, 'X-RateLimit-Limit' => $limit, 'X-RateLimit-Remaining' => 0]);
    }

    private function done(Request $request, ?int $clientId, float $t0, $response, ?int $limit = null, ?string $key = null)
    {
        if ($limit !== null && $key !== null && !$response->headers->has('X-RateLimit-Limit')) {
            $response->headers->set('X-RateLimit-Limit', (string) $limit);
            $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($key, $limit));
        }
        $response->headers->set('Cache-Control', 'no-store');
        Gateway::log($clientId, $request, $response->getStatusCode(), $t0);
        return $response;
    }
}
