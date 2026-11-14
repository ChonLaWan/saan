<?php

namespace App\Http\Controllers;

use App\Support\Saan\Edition;
use App\Support\Saan\Inbox\PullServer;
use App\Support\Saan\NetKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * GET /_saan/inbox and POST /_saan/inbox/ack (S5): the internal edition collects sealed envelopes. No session, no cookie, no CSRF: the request is
 * authenticated by its Ed25519 signature (see PullServer). Exists only for the public edition; every other edition answers 404.
 */
class SaanInboxController extends Controller
{
    public function handle(Request $request)
    {
        abort_unless(Edition::isPublic(), 404);
        // The signature decides first. Only a request that FAILED it is counted, and only such a request is ever answered 429: a correctly signed
        // request from the internal side is never refused, even when somebody else behind the same address (a NAT, a proxy) is guessing.
        $limiter = 'saan-inbox-auth:'.NetKey::rate($request->ip());
        [$status, $body] = (new PullServer())->handle($request->method(), $request->getPathInfo(), $request->query->all(), $request->getContent(), [
            'X-Saan-Timestamp' => (string) $request->header('X-Saan-Timestamp', ''),
            'X-Saan-Nonce' => (string) $request->header('X-Saan-Nonce', ''),
            'X-Saan-Signature' => (string) $request->header('X-Saan-Signature', ''),
        ]);
        if ($status === 401) {
            RateLimiter::hit($limiter, 600);
            if (RateLimiter::tooManyAttempts($limiter, 30)) return $this->reply(429, ['error' => 'rate_limited']);
        }
        return $this->reply($status, $body);
    }

    private function reply(int $status, array $body)
    {
        return response()->json($body, $status)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }
}
