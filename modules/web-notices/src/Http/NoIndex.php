<?php

namespace Saan\Modules\WebNotices\Http;

use Closure;
use Illuminate\Http\Request;

/** Every public notice response (page, form, 404) tells crawlers not to index, follow or archive. */
class NoIndex
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        return $response;
    }
}
