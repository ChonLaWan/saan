<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\Projects\Http\ActivityController as A;
use Saan\Modules\Projects\Http\PlanController as P;
use Saan\Modules\Projects\Http\ProjectController as J;
use Saan\Modules\Projects\Http\ReportController as R;

Route::middleware(['auth', CurrentSchoolAccount::class, 'role:admin,principal,teacher'])->prefix('api/projects')->group(function () {
    Route::get('/me', [P::class, 'me']);
    Route::get('/plans', [P::class, 'plans']);
    Route::get('/summary', [P::class, 'summary'])->middleware('throttle:120,1,get-api-projects-summary');
    Route::get('/export.csv', [P::class, 'exportCsv'])->middleware('throttle:20,1,get-api-projects-csv');

    Route::get('/projects', [J::class, 'index']);
    Route::get('/projects/{id}', [J::class, 'show'])->whereNumber('id');
    Route::get('/projects/{id}/form', [J::class, 'form'])->whereNumber('id')->middleware('throttle:60,1,get-api-projects-form');
    Route::post('/projects', [J::class, 'store'])->middleware('throttle:60,1,post-api-projects');
    Route::put('/projects/{id}', [J::class, 'update'])->whereNumber('id')->middleware('throttle:60,1,put-api-projects');
    Route::post('/projects/{id}/status', [J::class, 'status'])->whereNumber('id')->middleware('throttle:60,1,post-api-projects-status');
    Route::delete('/projects/{id}', [J::class, 'destroy'])->whereNumber('id')->middleware('throttle:30,1,delete-api-projects');

    Route::post('/projects/{id}/activities', [A::class, 'store'])->whereNumber('id')->middleware('throttle:120,1,post-api-projects-activities');
    Route::put('/activities/{id}', [A::class, 'update'])->whereNumber('id')->middleware('throttle:120,1,put-api-projects-activities');
    Route::delete('/activities/{id}', [A::class, 'destroy'])->whereNumber('id')->middleware('throttle:60,1,delete-api-projects-activities');

    Route::post('/projects/{id}/reports', [R::class, 'store'])->whereNumber('id')->middleware('throttle:30,1,post-api-projects-reports');
    Route::put('/reports/{id}', [R::class, 'update'])->whereNumber('id')->middleware('throttle:30,1,put-api-projects-reports');
    Route::delete('/reports/{id}', [R::class, 'destroy'])->whereNumber('id')->middleware('throttle:30,1,delete-api-projects-reports');
    Route::get('/reports/{id}/evidence', [R::class, 'evidence'])->whereNumber('id')->middleware('throttle:60,1,get-api-projects-evidence');

    Route::middleware('role:admin,principal')->group(function () {
        Route::post('/plans', [P::class, 'store'])->middleware('throttle:20,1,post-api-projects-plans');
        Route::put('/plans/{id}', [P::class, 'update'])->whereNumber('id')->middleware('throttle:30,1,put-api-projects-plans');
        Route::post('/plans/{id}/status', [P::class, 'status'])->whereNumber('id')->middleware('throttle:20,1,post-api-projects-plan-status');
        Route::delete('/plans/{id}', [P::class, 'destroy'])->whereNumber('id')->middleware('throttle:20,1,delete-api-projects-plans');
    });
});
