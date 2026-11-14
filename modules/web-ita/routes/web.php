<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\WebIta\Http\ItaController;

// Public pages (/portal/ita*) live in routes/public.php: they are the 'site' surface and are not loaded by the internal edition.

Route::middleware(['auth', CurrentSchoolAccount::class])->group(function () {
    Route::get('/api/ita/years', [ItaController::class, 'years']);
    Route::get('/api/ita/check', [ItaController::class, 'check'])->middleware('throttle:60,1,get-api-ita-check');
    Route::post('/api/ita/years', [ItaController::class, 'create'])->middleware('throttle:20,1,post-api-ita-years');
    Route::get('/api/ita/years/{id}', [ItaController::class, 'read'])->whereNumber('id');
    Route::put('/api/ita/years/{id}', [ItaController::class, 'save'])->whereNumber('id')->middleware('throttle:60,1,put-api-ita-years');
    Route::post('/api/ita/years/{id}/status', [ItaController::class, 'status'])->whereNumber('id')->middleware('throttle:30,1,post-api-ita-status');
});
