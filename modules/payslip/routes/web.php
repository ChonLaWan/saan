<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\Payslip\Http\CertController as C;
use Saan\Modules\Payslip\Http\ImportController as I;
use Saan\Modules\Payslip\Http\MeController as M;
use Saan\Modules\Payslip\Http\PayeeController as E;
use Saan\Modules\Payslip\Http\PeriodController as P;
use Saan\Modules\Payslip\Http\RequirePayroll;
use Saan\Modules\Payslip\Http\RequireSummary;

// Internal only (module.json edition = internal). Three layers: every signed-in staff account (own slips and own 50 tawi, always filtered by the linked payee),
// summaries (officer, admin, principal), and the payroll officer (everything that changes data or shows other people's rows).
Route::middleware(['auth', CurrentSchoolAccount::class, 'role:admin,principal,teacher,editor'])->prefix('api/payslip')->group(function () {
    Route::get('/me', [M::class, 'me']);
    Route::get('/my/slips', [M::class, 'slips'])->middleware('throttle:120,1,get-api-payslip-my-slips');
    Route::get('/my/slips/{id}', [M::class, 'show'])->whereNumber('id')->middleware('throttle:60,1,get-api-payslip-my-slip');
    Route::get('/my/slips/{id}/print', [M::class, 'print'])->whereNumber('id')->middleware('throttle:60,1,get-api-payslip-my-slip-print');
    Route::get('/my/slips/{id}/download', [M::class, 'download'])->whereNumber('id')->middleware('throttle:30,1,get-api-payslip-my-slip-download');
    Route::get('/my/certificates', [M::class, 'certs'])->middleware('throttle:120,1,get-api-payslip-my-certs');
    Route::get('/my/certificates/{id}', [M::class, 'cert'])->whereNumber('id')->middleware('throttle:60,1,get-api-payslip-my-cert');
    Route::get('/my/certificates/{id}/print', [M::class, 'certPrint'])->whereNumber('id')->middleware('throttle:30,1,get-api-payslip-my-cert-print');

    Route::middleware(RequireSummary::class)->group(function () {
        Route::get('/periods', [P::class, 'index']);
        Route::get('/certificates', [C::class, 'summary']);
    });

    Route::middleware(RequirePayroll::class)->group(function () {
        Route::get('/payees', [E::class, 'index']);
        Route::get('/payees/candidates', [E::class, 'candidates']);
        Route::post('/payees', [E::class, 'store'])->middleware('throttle:60,1,post-api-payslip-payees');
        Route::put('/payees/{id}', [E::class, 'update'])->whereNumber('id')->middleware('throttle:60,1,put-api-payslip-payees');

        Route::get('/mapping', [I::class, 'mapping']);
        Route::put('/mapping', [I::class, 'saveMapping'])->middleware('throttle:20,1,put-api-payslip-mapping');
        Route::post('/headers', [I::class, 'headers'])->middleware('throttle:30,1,post-api-payslip-headers');

        Route::post('/periods', [P::class, 'store'])->middleware('throttle:30,1,post-api-payslip-periods');
        Route::get('/periods/{id}', [P::class, 'show'])->whereNumber('id');
        Route::post('/periods/{id}/imports', [I::class, 'stage'])->whereNumber('id')->middleware('throttle:20,1,post-api-payslip-import');
        Route::post('/periods/{id}/pdfs', [I::class, 'pdfs'])->whereNumber('id')->middleware('throttle:20,1,post-api-payslip-pdfs');
        Route::post('/periods/{id}/publish', [P::class, 'publish'])->whereNumber('id')->middleware('throttle:20,1,post-api-payslip-publish');
        Route::post('/periods/{id}/cancel', [P::class, 'cancel'])->whereNumber('id')->middleware('throttle:20,1,post-api-payslip-cancel');
        Route::get('/imports/{id}', [I::class, 'show'])->whereNumber('id');
        Route::post('/imports/{id}/confirm', [I::class, 'confirm'])->whereNumber('id')->middleware('throttle:20,1,post-api-payslip-confirm');
        Route::post('/slips/{id}/void', [P::class, 'voidSlip'])->whereNumber('id')->middleware('throttle:60,1,post-api-payslip-void');
        Route::post('/slips/{id}/replace', [P::class, 'replaceSlip'])->whereNumber('id')->middleware('throttle:60,1,post-api-payslip-replace');

        Route::post('/certificates/generate', [C::class, 'generate'])->middleware('throttle:10,1,post-api-payslip-cert-generate');
        Route::get('/certificates/{id}/print', [C::class, 'print'])->whereNumber('id')->middleware('throttle:60,1,get-api-payslip-cert-print');
    });
});
