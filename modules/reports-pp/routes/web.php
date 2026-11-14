<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\ReportsPp\Http\ReportsController as R;

// ปพ.5 / special results: the course teacher or admin/principal (checked in ReportsPp::course()).
// ปพ.6 / class summary / remarks: homeroom teacher of the classroom or admin/principal (checked in ReportsPp::assertHomeroom()).
Route::middleware(['auth', CurrentSchoolAccount::class, 'role:admin,principal,teacher'])->group(function () {
    Route::prefix('api/reports-pp')->group(function () {
        Route::get('/overview', [R::class, 'overview']);
        Route::get('/courses/{id}/special', [R::class, 'specialList'])->whereNumber('id');
        Route::put('/courses/{id}/special', [R::class, 'specialSave'])->whereNumber('id')->middleware('throttle:120,1,put-api-reports-special');
        Route::get('/courses/{id}/csv', [R::class, 'pp5Csv'])->whereNumber('id')->middleware('throttle:30,1,get-api-reports-pp5-csv');
        Route::get('/classrooms/{id}/remarks', [R::class, 'remarkList'])->whereNumber('id');
        Route::get('/classrooms/{id}/csv', [R::class, 'pp6Csv'])->whereNumber('id')->middleware('throttle:30,1,get-api-reports-pp6-csv');
        Route::get('/classrooms/{id}/summary/csv', [R::class, 'summaryCsv'])->whereNumber('id')->middleware('throttle:30,1,get-api-reports-summary-csv');
        Route::put('/remarks', [R::class, 'remarkSave'])->middleware('throttle:120,1,put-api-reports-remarks');
    });

    Route::prefix('print/pp')->middleware('throttle:60,1,get-print-pp')->group(function () {
        Route::get('/course/{id}', [R::class, 'pp5'])->whereNumber('id');
        Route::get('/student/{id}', [R::class, 'pp6Student'])->whereNumber('id');
        Route::get('/classroom/{id}', [R::class, 'pp6Class'])->whereNumber('id');
        Route::get('/classroom/{id}/summary', [R::class, 'summary'])->whereNumber('id');
    });
});

Route::middleware(['auth', CurrentSchoolAccount::class, 'role:admin,principal'])->prefix('api/reports-pp')->group(function () {
    Route::put('/settings', [R::class, 'settingsSave'])->middleware('throttle:30,1,put-api-reports-settings');
    Route::put('/templates', [R::class, 'templateSave'])->middleware('throttle:30,1,put-api-reports-templates');
});
