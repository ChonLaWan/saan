<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class LocalPrototype {
    public function handle(Request $request, Closure $next) {
        abort_unless(config('school.demo') && in_array($request->ip(), ['127.0.0.1','::1']), 403, 'Local prototype only');
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }
}
