<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\Finance\Http\AccountController as A;
use Saan\Modules\Finance\Http\BillingController as B;
use Saan\Modules\Finance\Http\ClaimController as C;
use Saan\Modules\Finance\Http\ReportController as R;
use Saan\Modules\Finance\Http\RequireFinance;
use Saan\Modules\Finance\Http\SetupController as S;

// Teachers are allowed through the role gate only so /me can tell them why they have no access; every other route needs finance staff.
Route::middleware(['auth', CurrentSchoolAccount::class, 'role:admin,principal,teacher'])->prefix('api/finance')->group(function () {
    Route::get('/me', [S::class, 'me']);

    Route::middleware(RequireFinance::class)->group(function () {
        Route::get('/students', [B::class, 'search'])->middleware('throttle:240,1,get-api-finance-students');
        Route::get('/students/{id}/account', [B::class, 'account'])->whereNumber('id')->middleware('throttle:240,1,get-api-finance-account');
        Route::get('/lookups', [S::class, 'lookups']);
        Route::get('/fee-items', [S::class, 'feeItems']);
        Route::get('/invoices', [B::class, 'invoices']);
        Route::get('/invoices.csv', [B::class, 'exportInvoices'])->middleware('throttle:20,1,get-api-finance-invoices-csv');
        Route::get('/invoices/{id}', [B::class, 'showInvoice'])->whereNumber('id');
        Route::post('/invoices', [B::class, 'storeInvoice'])->middleware('throttle:60,1,post-api-finance-invoices');
        Route::post('/invoices/bulk/preview', [B::class, 'bulkPreview'])->middleware('throttle:30,1,post-api-finance-bulk-preview');
        Route::post('/invoices/bulk', [B::class, 'bulkCommit'])->middleware('throttle:10,1,post-api-finance-bulk');
        Route::post('/invoices/{id}/void', [B::class, 'voidInvoice'])->whereNumber('id')->middleware('throttle:30,1,post-api-finance-invoice-void');
        Route::post('/invoices/{id}/payments', [B::class, 'pay'])->whereNumber('id')->middleware('throttle:120,1,post-api-finance-payments');
        Route::post('/payments/{id}/void', [B::class, 'voidPayment'])->whereNumber('id')->middleware('throttle:30,1,post-api-finance-payment-void');
        Route::get('/payments/{id}/receipt', [B::class, 'receipt'])->whereNumber('id')->middleware('throttle:120,1,get-api-finance-receipt');

        Route::get('/classrooms/outstanding', [B::class, 'classroomSummary'])->middleware('throttle:60,1,get-api-finance-classroom-outstanding');

        // F3 (internal only): receiving accounts, QR images, guardian payment claims (verification queue)
        Route::get('/accounts', [A::class, 'index']);
        Route::get('/accounts/{id}/qr', [A::class, 'qrShow'])->whereNumber('id')->middleware('throttle:240,1,get-api-finance-account-qr');
        Route::get('/claims', [C::class, 'index'])->middleware('throttle:240,1,get-api-finance-claims');
        Route::get('/claims/{id}', [C::class, 'show'])->whereNumber('id')->middleware('throttle:240,1,get-api-finance-claim');
        Route::get('/claims/{id}/slips/{slip}', [C::class, 'slip'])->whereNumber('id')->whereNumber('slip')->middleware('throttle:240,1,get-api-finance-claim-slip');
        Route::post('/claims/{id}/approve', [C::class, 'approve'])->whereNumber('id')->middleware('throttle:120,1,post-api-finance-claim-approve');
        Route::post('/claims/{id}/reject', [C::class, 'reject'])->whereNumber('id')->middleware('throttle:120,1,post-api-finance-claim-reject');
        Route::post('/invoices/{id}/claims', [C::class, 'store'])->whereNumber('id')->middleware('throttle:60,1,post-api-finance-invoice-claims');
        Route::get('/reports/daily', [R::class, 'dailySummary']);
        Route::get('/reports/daily.csv', [R::class, 'dailyCsv'])->middleware('throttle:20,1,get-api-finance-daily-csv');
        Route::get('/ledger', [R::class, 'ledgerList']);
        Route::get('/ledger.csv', [R::class, 'ledgerCsv'])->middleware('throttle:20,1,get-api-finance-ledger-csv');
        Route::post('/ledger', [R::class, 'ledgerStore'])->middleware('throttle:60,1,post-api-finance-ledger');
        Route::delete('/ledger/{id}', [R::class, 'ledgerDelete'])->whereNumber('id')->middleware('throttle:30,1,delete-api-finance-ledger');
        Route::get('/periods', [S::class, 'periods']);
    });

    Route::middleware('role:admin,principal')->group(function () {
        Route::post('/fee-items', [S::class, 'storeFee'])->middleware('throttle:60,1,post-api-finance-fee-items');
        Route::put('/fee-items/{id}', [S::class, 'updateFee'])->whereNumber('id')->middleware('throttle:60,1,put-api-finance-fee-items');
        // where the money goes is a manager decision: finance staff at teacher level may read accounts but not change them
        Route::post('/accounts', [A::class, 'store'])->middleware('throttle:30,1,post-api-finance-accounts');
        Route::put('/accounts/{id}', [A::class, 'update'])->whereNumber('id')->middleware('throttle:30,1,put-api-finance-accounts');
        Route::post('/accounts/{id}/qr', [A::class, 'qrStore'])->whereNumber('id')->middleware('throttle:20,1,post-api-finance-account-qr');
        Route::delete('/accounts/{id}/qr', [A::class, 'qrDelete'])->whereNumber('id')->middleware('throttle:20,1,delete-api-finance-account-qr');
        Route::post('/periods/{period}/close', [S::class, 'close'])->middleware('throttle:20,1,post-api-finance-period-close');
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/settings', [S::class, 'settings']);
        Route::put('/settings', [S::class, 'saveSettings'])->middleware('throttle:20,1,put-api-finance-settings');
        Route::post('/periods/{period}/reopen', [S::class, 'reopen'])->middleware('throttle:20,1,post-api-finance-period-reopen');
    });
});

