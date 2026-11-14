<?php

namespace App\Http\Middleware;

use App\Support\Modules\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;

class RequireModule
{
    public function __construct(private ModuleRegistry $modules) {}

    public function handle(Request $request, Closure $next, string $module)
    {
        abort_unless($this->modules->active($module), 404);
        return $next($request);
    }
}
