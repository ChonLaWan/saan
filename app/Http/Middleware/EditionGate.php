<?php

namespace App\Http\Middleware;

use App\Support\Saan\Edition;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * Defence in depth for the editions. The public edition registers only public routes and this global middleware also answers 404
 * for everything outside its allowlist (Edition::PUBLIC_ALLOWED) before the session or any route code runs. The internal edition
 * is never indexed (X-Robots-Tag). Cached routes carry the marker route "saan.edition.<edition>": a route cache built under another
 * edition is refused instead of served, because the cache would otherwise freeze the wrong route set.
 */
class EditionGate
{
    public function handle(Request $request, Closure $next)
    {
        self::assertRoutesMatchEdition(app()->routesAreCached());
        \App\Support\Saan\Publish\SplitHealth::noteForwarded($request);
        if (Edition::isPublic()) \App\Support\Saan\Publish\SplitHealth::observeWeb();
        if (Edition::isPublic() && Edition::blockedInPublic($request->getPathInfo())) abort(404);
        $response = $next($request);
        if (Edition::isInternal()) $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }

    /** Throws when the route cache was built for a different edition than config('saan.edition') (php artisan route:clear, then route:cache again). */
    public static function assertRoutesMatchEdition(bool $cached): void
    {
        if (!$cached) return;
        $want = Edition::current();
        if (!Route::has('saan.edition.'.$want)) {
            throw new RuntimeException('route cache สร้างไว้ภายใต้โหมดอื่น (SAAN_EDITION='.$want.' ไม่ตรง) ให้รัน php artisan route:clear แล้ว route:cache ใหม่');
        }
    }
}