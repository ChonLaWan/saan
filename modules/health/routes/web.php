<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\Health\Http\HealthController;

// Health data: admin, principal and homeroom teachers only (class access is checked again per request).
Route::middleware(['auth', CurrentSchoolAccount::class, 'role:admin,principal,teacher'])->prefix('api/health')->group(function () {
    Route::get('/classrooms', [HealthController::class, 'classrooms']);
    Route::get('/summary', [HealthController::class, 'overview']);
    Route::get('/classrooms/{id}/grid', [HealthController::class, 'grid'])->whereNumber('id');
    Route::put('/classrooms/{id}/grid', [HealthController::class, 'save'])->whereNumber('id')->middleware('throttle:60,1,put-api-health-grid');
    Route::get('/classrooms/{id}/summary', [HealthController::class, 'summary'])->whereNumber('id');
    Route::get('/classrooms/{id}/export.csv', [HealthController::class, 'export'])->whereNumber('id')->middleware('throttle:30,1,get-api-health-export');
    Route::get('/students/{id}/profile', [HealthController::class, 'profile'])->whereNumber('id')->middleware('throttle:60,1,get-api-health-profile');
    Route::put('/students/{id}/profile', [HealthController::class, 'saveProfile'])->whereNumber('id')->middleware('throttle:30,1,put-api-health-profile');
});
