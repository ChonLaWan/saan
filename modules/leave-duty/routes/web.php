<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\LeaveDuty\Http\DutyController as D;
use Saan\Modules\LeaveDuty\Http\LeaveController as L;

// Staff: admin, principal, teacher. Approvers (decisions, all requests, quota report, roster writes): admin, principal.
Route::middleware(['auth', CurrentSchoolAccount::class])->group(function () {
    Route::prefix('api/leave')->middleware('role:admin,principal,teacher')->group(function () {
        Route::get('/types', [L::class, 'types']);
        Route::get('/preview', [L::class, 'preview'])->middleware('throttle:120,1,get-api-leave-preview');
        Route::get('/quota', [L::class, 'quota']);
        Route::get('/requests', [L::class, 'index']);
        Route::get('/requests/{id}', [L::class, 'show'])->whereNumber('id');
        Route::post('/requests', [L::class, 'store'])->middleware('throttle:30,1,post-api-leave-requests');
        Route::put('/requests/{id}', [L::class, 'update'])->whereNumber('id')->middleware('throttle:60,1,put-api-leave-requests');
        Route::delete('/requests/{id}', [L::class, 'destroy'])->whereNumber('id')->middleware('throttle:30,1,delete-api-leave-requests');
        Route::post('/requests/{id}/transition', [L::class, 'transition'])->whereNumber('id')->middleware('throttle:60,1,post-api-leave-transition');
        Route::post('/requests/{id}/resubmit', [L::class, 'resubmit'])->whereNumber('id')->middleware('throttle:20,1,post-api-leave-resubmit');
        Route::post('/requests/{id}/attachment', [L::class, 'attach'])->whereNumber('id')->middleware('throttle:20,1,post-api-leave-attachment');
        Route::delete('/requests/{id}/attachment', [L::class, 'detach'])->whereNumber('id')->middleware('throttle:30,1,delete-api-leave-attachment');
        Route::get('/requests/{id}/attachment', [L::class, 'download'])->whereNumber('id')->middleware('throttle:60,1,get-api-leave-attachment');

        Route::middleware('role:admin,principal')->group(function () {
            Route::get('/quota/report', [L::class, 'report']);
            Route::post('/types', [L::class, 'typeStore'])->middleware('throttle:20,1,post-api-leave-types');
            Route::put('/types/{id}', [L::class, 'typeUpdate'])->whereNumber('id')->middleware('throttle:30,1,put-api-leave-types');
            Route::delete('/types/{id}', [L::class, 'typeDestroy'])->whereNumber('id')->middleware('throttle:20,1,delete-api-leave-types');
        });
    });

    Route::prefix('api/duty')->middleware('role:admin,principal,teacher')->group(function () {
        Route::get('/teachers', [D::class, 'teachers']);
        Route::get('/duties', [D::class, 'index']);
        Route::get('/swaps', [D::class, 'swaps']);
        Route::post('/duties/{id}/swaps', [D::class, 'swapStore'])->whereNumber('id')->middleware('throttle:20,1,post-api-duty-swap-request');
        Route::post('/swaps/{id}/transition', [D::class, 'swapTransition'])->whereNumber('id')->middleware('throttle:60,1,post-api-duty-swap-transition');

        Route::middleware('role:admin,principal')->group(function () {
            Route::post('/duties', [D::class, 'store'])->middleware('throttle:60,1,post-api-duty-duties');
            Route::put('/duties/{id}', [D::class, 'update'])->whereNumber('id')->middleware('throttle:60,1,put-api-duty-duties');
            Route::delete('/duties/{id}', [D::class, 'destroy'])->whereNumber('id')->middleware('throttle:30,1,delete-api-duty-duties');
            Route::post('/duties/{id}/cancel', [D::class, 'cancel'])->whereNumber('id')->middleware('throttle:30,1,post-api-duty-cancel');
        });
    });
});
