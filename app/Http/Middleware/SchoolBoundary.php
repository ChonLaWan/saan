<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SchoolBoundary
{
    public function handle(Request $request, Closure $next)
    {
        app()->setLocale('th');
        // Local installation stays private even when demo login is switched off.
        if (config('school.local_only') || config('school.demo')) {
            abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);
        }

        app(\App\Support\PublicCsp::class)->reset();
        $response = $next($request);
        // F5a: a page that holds the map block names its one provider frame source (frame-src only); every other page keeps the policy it had
        if (($csp = app(\App\Support\PublicCsp::class)->value()) !== null && !$response->headers->has('Content-Security-Policy')) $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // a response that chose a stricter policy itself (the notice rows JSON uses no-referrer) keeps it
        if (!$response->headers->has('Referrer-Policy')) $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        // a controller that serves guest-visible static files (published images) opts out of the blanket no-store with this marker
        if ($response->headers->has('X-Saan-Public-Cache')) $response->headers->remove('X-Saan-Public-Cache');
        else $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
