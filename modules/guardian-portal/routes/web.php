<?php

use App\Http\Middleware\CurrentSchoolAccount;
use App\Support\Saan\Edition;
use Illuminate\Support\Facades\Route;
use Saan\Modules\GuardianPortal\Http\FeesController as F;
use Saan\Modules\GuardianPortal\Http\GuardianController as G;
use Saan\Modules\GuardianPortal\Http\GuardianSession;
use Saan\Modules\GuardianPortal\Http\NoIndex;
use Saan\Modules\GuardianPortal\Http\PortalAccountController as P;
use Saan\Modules\GuardianPortal\Http\StaffController as S;

// Guardian side: no staff login. Access comes from a one-time link or a guardian account (see Access / Links).
// Edition: the 'guardian' surface is internal-only; the public edition never registers it.
if (Edition::serves('guardian')) {
    Route::prefix('guardian')->middleware(NoIndex::class)->group(function () {
        Route::get('/', [G::class, 'shell']);
        Route::get('/l/{token}', [G::class, 'confirm'])->where('token', '[A-Za-z0-9_-]{20,64}')->middleware('throttle:60,1,get-guardian-link');
        Route::post('/l/{token}', [G::class, 'open'])->where('token', '[A-Za-z0-9_-]{20,64}')->middleware('throttle:30,1,post-guardian-open');
        Route::post('/login', [G::class, 'login'])->middleware('throttle:20,1,post-guardian-login');
        Route::post('/logout', [G::class, 'logout'])->middleware('throttle:60,1,post-guardian-logout');
        // F8: forgot password (verified e-mail only), reset link, e-mail verification; identical answers whether or not an account exists
        Route::get('/forgot', [P::class, 'forgotPage'])->middleware('throttle:60,1,get-guardian-forgot');
        Route::post('/forgot', [P::class, 'forgot'])->middleware('throttle:10,1,post-guardian-forgot');
        Route::get('/reset/{token}', [P::class, 'resetPage'])->where('token', '[A-Za-z0-9_-]{20,200}')->middleware('throttle:60,1,get-guardian-reset');
        Route::post('/reset', [P::class, 'reset'])->middleware('throttle:20,1,post-guardian-reset');
        Route::get('/verify/{token}', [P::class, 'verifyPage'])->where('token', '[A-Za-z0-9_-]{20,64}')->middleware('throttle:60,1,get-guardian-verify');
        Route::post('/verify/{token}', [P::class, 'verify'])->where('token', '[A-Za-z0-9_-]{20,64}')->middleware('throttle:30,1,post-guardian-verify');

        Route::middleware(GuardianSession::class)->prefix('api')->group(function () {
            Route::middleware('throttle:120,1,get-guardian-api')->group(function () {
                Route::get('/me', [P::class, 'me']);
                Route::get('/children', [G::class, 'children']);
                Route::get('/children/{id}/attendance', [G::class, 'attendance'])->whereNumber('id');
                Route::get('/children/{id}/behavior', [G::class, 'behavior'])->whereNumber('id');
                Route::get('/children/{id}/results', [G::class, 'results'])->whereNumber('id');
                Route::get('/children/{id}/report', [G::class, 'report'])->whereNumber('id');
                Route::get('/children/{id}/timetable', [G::class, 'timetable'])->whereNumber('id');
                Route::get('/children/{id}/health', [G::class, 'health'])->whereNumber('id');
                // F3 fees: invoices, payment accounts, slips, receipts (every route re-checks child, invoice, claim and owner)
                Route::get('/children/{id}/fees', [F::class, 'fees'])->whereNumber('id');
                Route::get('/children/{id}/invoices/{invoice}/accounts/{account}/qr', [F::class, 'qr'])->whereNumber(['id', 'invoice', 'account']);
                Route::get('/children/{id}/receipts/{payment}', [F::class, 'receipt'])->whereNumber(['id', 'payment']);
                Route::get('/children/{id}/claims/{claim}/slips/{slip}', [F::class, 'slip'])->whereNumber(['id', 'claim', 'slip']);
            });
            Route::post('/children/{id}/invoices/{invoice}/claims', [F::class, 'claim'])->whereNumber(['id', 'invoice'])->middleware('throttle:30,1,post-guardian-claim');
            Route::post('/children/{id}/claims/{claim}/withdraw', [F::class, 'withdraw'])->whereNumber(['id', 'claim'])->middleware('throttle:30,1,post-guardian-claim-withdraw');
            Route::post('/children/{id}/notices/read', [F::class, 'readNotices'])->whereNumber('id')->middleware('throttle:60,1,post-guardian-notices-read');
            Route::post('/secret', [P::class, 'secret'])->middleware('throttle:20,1,post-guardian-secret');
            Route::post('/email', [P::class, 'email'])->middleware('throttle:10,1,post-guardian-email');
            Route::post('/children/{id}/ack', [G::class, 'ack'])->whereNumber('id')->middleware('throttle:30,1,post-guardian-ack');
        });
    });
}

// Staff side (work page #guardians): admin/principal everywhere, teacher only the homeroom (checked in the controller and Links).
Route::middleware(['auth', CurrentSchoolAccount::class, 'role:admin,principal,teacher'])->prefix('api/guardian-portal')->group(function () {
    Route::get('/context', [S::class, 'context']);
    Route::get('/classrooms/{id}/guardians', [S::class, 'guardians'])->whereNumber('id');
    Route::post('/links', [S::class, 'createLink'])->middleware('throttle:60,1,post-api-guardian-portal-links');
    Route::delete('/links/{id}', [S::class, 'revokeLink'])->whereNumber('id')->middleware('throttle:60,1,delete-api-guardian-portal-links');
    Route::put('/accounts/{guardianId}', [S::class, 'disableAccount'])->whereNumber('guardianId')->middleware('throttle:20,1,put-api-guardian-portal-accounts');
    // F8: announce term results to students and guardians; temporary guardian passwords live in the student-portal staff page
    Route::get('/terms', [S::class, 'terms']);
    Route::put('/terms/{id}/announce', [S::class, 'announce'])->whereNumber('id')->middleware(['role:admin,principal', 'throttle:20,1,put-api-guardian-portal-announce']);    Route::put('/settings', [S::class, 'settings'])->middleware(['role:admin,principal', 'throttle:20,1,put-api-guardian-portal-settings']);
});
