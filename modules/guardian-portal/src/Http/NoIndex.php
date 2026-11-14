<?php

namespace Saan\Modules\GuardianPortal\Http;

use Closure;
use Illuminate\Http\Request;

/** Every guardian page (including errors) tells crawlers not to index, follow or archive. */
class NoIndex
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        return $response;
    }
}
