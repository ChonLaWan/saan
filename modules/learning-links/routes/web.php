<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\LearningLinks\Http\LinkController as L;

// The public classroom page (/learn/*) lives in routes/public.php (the 'site' surface).

Route::middleware(['auth', CurrentSchoolAccount::class, 'role:admin,principal,teacher'])->prefix('api/learning')->group(function () {
    Route::get('/options', [L::class, 'options']);
    Route::get('/links', [L::class, 'index']);
    Route::post('/links', [L::class, 'store'])->middleware('throttle:60,1,post-api-learning-links');
    Route::put('/links/{id}', [L::class, 'update'])->whereNumber('id')->middleware('throttle:60,1,put-api-learning-links');
    Route::delete('/links/{id}', [L::class, 'destroy'])->whereNumber('id')->middleware('throttle:60,1,delete-api-learning-links');
    Route::get('/classrooms/{id}/access', [L::class, 'accessShow'])->whereNumber('id');
    Route::post('/classrooms/{id}/access', [L::class, 'accessIssue'])->whereNumber('id')->middleware('throttle:20,1,post-api-learning-access');
    Route::delete('/classrooms/{id}/access', [L::class, 'accessRevoke'])->whereNumber('id')->middleware('throttle:20,1,delete-api-learning-access');
});
