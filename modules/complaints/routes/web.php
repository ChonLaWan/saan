<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\Complaints\Http\CaseController as C;

// Public pages (/portal/complaints*) live in routes/public.php (the 'site' surface). Everything here is the staff side; the real access rules
// (officers, executives for corruption cases, the accused of a case) are applied per row in Saan\Modules\Complaints\Access.
Route::middleware(['auth', CurrentSchoolAccount::class, 'role:admin,principal,teacher,editor'])->prefix('api/complaints')->group(function () {
    Route::get('/meta', [C::class, 'meta']);
    Route::get('/cases', [C::class, 'index']);
    Route::get('/cases/export', [C::class, 'export'])->middleware('throttle:20,1,get-api-complaints-export');
    Route::get('/cases/{id}', [C::class, 'show'])->whereNumber('id');
    Route::post('/cases/{id}/assign', [C::class, 'assign'])->whereNumber('id')->middleware('throttle:60,1,post-api-complaints-assign');
    Route::post('/cases/{id}/due', [C::class, 'due'])->whereNumber('id')->middleware('throttle:60,1,post-api-complaints-due');
    Route::post('/cases/{id}/accused', [C::class, 'accused'])->whereNumber('id')->middleware('throttle:30,1,post-api-complaints-accused');
    Route::post('/cases/{id}/reply', [C::class, 'reply'])->whereNumber('id')->middleware('throttle:60,1,post-api-complaints-reply');
    Route::post('/cases/{id}/track-code', [C::class, 'reissue'])->whereNumber('id')->middleware('throttle:30,1,post-api-complaints-track-code');
    Route::post('/cases/{id}/close', [C::class, 'close'])->whereNumber('id')->middleware('throttle:60,1,post-api-complaints-close');
    Route::post('/cases/{id}/reopen', [C::class, 'reopen'])->whereNumber('id')->middleware('throttle:60,1,post-api-complaints-reopen');
    Route::post('/cases/{id}/notes', [C::class, 'note'])->whereNumber('id')->middleware('throttle:60,1,post-api-complaints-notes');
    Route::get('/cases/{id}/files/{fileId}', [C::class, 'file'])->whereNumber('id')->whereNumber('fileId')->middleware('throttle:120,1,get-api-complaints-file');
    Route::get('/reports', [C::class, 'reports']);
    Route::get('/reports/{year}', [C::class, 'report'])->whereNumber('year');
    Route::get('/reports/{year}/csv', [C::class, 'reportCsv'])->whereNumber('year')->middleware('throttle:20,1,get-api-complaints-report-csv');
    Route::post('/reports/{year}/publish', [C::class, 'publish'])->whereNumber('year')->middleware('throttle:20,1,post-api-complaints-report-publish');
});
