<?php

namespace App\Http\Controllers;

use App\Support\Saan\Edition;
use App\Support\Saan\Publish\Receiver;
use Illuminate\Http\Request;

/**
 * POST /_saan/push (S4): the only endpoint through which the internal edition writes into this one. No session, no cookie, no CSRF:
 * the request is authenticated by its Ed25519 signature (see Receiver). Exists only for the public edition; every other edition answers 404.
 */
class SaanPushController extends Controller
{
    public function receive(Request $request)
    {
        abort_unless(Edition::isPublic(), 404);
        $len = (int) $request->header('Content-Length', 0);
        if ($len > max(64, (int) config('saan.push.max_body_kb', 8192)) * 1024) return $this->reply(413, ['error' => 'too_large']);
        [$status, $body] = (new Receiver())->handle($request->getContent(), [
            'X-Saan-Timestamp' => (string) $request->header('X-Saan-Timestamp', ''),
            'X-Saan-Signature' => (string) $request->header('X-Saan-Signature', ''),
        ]);
        // no per-address route throttle: only a request that failed authentication is counted, and only such a request is answered 429
        if ($status === 401) {
            $limiter = 'saan-push-auth:'.\App\Support\Saan\NetKey::rate($request->ip());
            \Illuminate\Support\Facades\RateLimiter::hit($limiter, 600);
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($limiter, 30)) return $this->reply(429, ['error' => 'rate_limited']);
        }
        return $this->reply($status, $body);
    }

    private function reply(int $status, array $body)
    {
        return response()->json($body, $status)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }
}
