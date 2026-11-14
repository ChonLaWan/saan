<?php
namespace App\Http\Middleware;
use App\Domain\Cms\Redirects;
use App\Support\WebsiteView;
use Closure;
use Illuminate\Http\Request;
class PublicRedirect
{
    public function handle(Request $request,Closure $next)
    {
        if($request->isMethod('GET')&&($row=Redirects::match($request->getPathInfo(),$request->getQueryString()??'')))return redirect(WebsiteView::link($row->target_path),$row->status_code);
        return$next($request);
    }
}
