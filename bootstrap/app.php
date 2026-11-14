<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // REL-02 finish step over HTTP (hosts without proc_open), POST /_saan/update-finish: no session, no CSRF, both editions; the caller proves itself with the
        // one-time token of the update folder. Registered outside the web group and exempt from maintenance mode, which is on while it runs.
        then: function (): void {
            \Illuminate\Support\Facades\Route::post('/_saan/update-finish', [\App\Http\Controllers\UpdateController::class, 'finish'])->middleware('throttle:300,1,post-saan-update-finish'); // generous: the finisher's one request must never be starved by probes from a shared address
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->preventRequestsDuringMaintenance(except: ['_saan/update-finish']);
        // Trusted proxies are applied in AppServiceProvider from config('saan.trusted_proxies') (env() is not loaded yet at this point).
        // S1: the public edition answers 404 for every internal path prefix before anything else runs (the routes are not registered there either).
        $middleware->prepend(\App\Http\Middleware\EditionGate::class);
        $middleware->alias(['module' => \App\Http\Middleware\RequireModule::class, 'role' => \App\Http\Middleware\RequireRole::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Expired session on a form: the public survey form goes back with the typed input and a Thai message instead of Laravel's English
        // "Page Expired" page; JSON callers get a Thai 419 message; anything else falls to the Thai resources/views/errors/419.blade.php.
        // (Laravel converts TokenMismatchException into an HttpException with status 419 before render callbacks run.)
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() !== 419) return null;
            $msg = 'หน้าเว็บหมดเวลา กรุณากดส่งอีกครั้ง';
            if ($request->expectsJson()) return response()->json(['message' => $msg], 419);
            // only views that print $errors can show the message (surveys); the others get the Thai 419 page
            if ($request->is('portal/surveys/*') && !$request->isMethodSafe()) {
                return redirect()->back(fallback: $request->fullUrl())->withInput($request->except(['password', 'password_confirmation', 'code', 'pin', 'access_code', '_token']))->withErrors(['session' => $msg]);
            }
            return null;
        });
        $exceptions->render(function (\App\Support\Saan\VersionConflict $e, \Illuminate\Http\Request $request) {
            return response()->json(['message' => 'ข้อมูลถูกแก้ไขโดยผู้อื่นแล้ว กรุณาโหลดใหม่', 'current' => $e->current], 409);
        });
    })->create();
