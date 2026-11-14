<?php

namespace Saan\Modules\ApiGateway;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Client lookup, bearer tokens, request log and OAuth-style JSON errors. */
class Gateway
{
    public const TOKEN_TTL = 3600;
    public const CODE_TTL = 120;

    public static function school(): int
    {
        return (int) config('school.school_id');
    }

    public static function error(string $code, string $description, int $status, array $headers = []): JsonResponse
    {
        return response()->json(['error' => $code, 'error_description' => $description], $status, $headers + ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    public static function client(string $clientId): ?object
    {
        return DB::table('api_clients')->where('school_id', self::school())->where('client_id', $clientId)->first();
    }

    public static function clientScopes(object $client): array
    {
        return self::json($client->scopes);
    }

    public static function json(mixed $value): array
    {
        if (is_array($value)) return $value;
        $d = json_decode((string) $value, true);
        return is_array($d) ? $d : [];
    }

    /**
     * Check a client secret in constant effort: an unknown client still pays for one hash check,
     * so response time does not tell which client ids exist.
     */
    public static function secretMatches(?object $client, string $secret): bool
    {
        // one Hash::make costs what one Hash::check costs; no static dummy hash (under PHP-FPM it would add a second bcrypt to this branch)
        if (!$client) { Hash::make('saan-dummy-secret'); return false; }
        return Hash::check($secret, $client->secret_hash);
    }

    /** @return array{0:string,1:int} plain bearer and lifetime in seconds. The bearer is shown once and only its sha256 is stored. */
    public static function issueToken(object $client, array $scopes, ?string $userKind = null, ?int $subjectId = null, ?int $codeId = null): array
    {
        $plain = 'saan_'.bin2hex(random_bytes(32));
        DB::table('api_tokens')->insert([
            'client_id' => $client->id, 'token_hash' => hash('sha256', $plain), 'scopes' => json_encode(array_values($scopes)),
            'user_kind' => $userKind, 'subject_id' => $subjectId, 'sso_code_id' => $codeId,
            'expires_at' => CarbonImmutable::now()->addSeconds(self::TOKEN_TTL), 'created_at' => now(),
        ]);
        return [$plain, self::TOKEN_TTL];
    }

    public static function revokeTokens(int $clientId, ?int $codeId = null): void
    {
        $q = DB::table('api_tokens')->where('client_id', $clientId)->whereNull('revoked_at');
        if ($codeId !== null) $q->where('sso_code_id', $codeId);
        $q->update(['revoked_at' => now()]);
    }

    public static function log(?int $clientId, Request $request, int $status, float $startedAt, ?string $route = null): void
    {
        try {
            DB::table('api_request_log')->insert([
                'client_id' => $clientId, 'route' => mb_substr($route ?? ($request->route()?->uri() ?? $request->path()), 0, 120),
                'status' => $status, 'ms' => (int) round((microtime(true) - $startedAt) * 1000), 'at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e); // logging must never break the API call itself
        }
    }
}
