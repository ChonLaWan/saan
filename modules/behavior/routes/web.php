<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\Behavior\Http\BehaviorController as C;

Route::middleware(['auth', CurrentSchoolAccount::class])->prefix('api/behavior')->group(function () {
    Route::middleware('role:admin,principal,teacher')->group(function () {
        Route::get('/context', [C::class, 'context']);
        Route::get('/rules', [C::class, 'rules']);
        Route::get('/classrooms/{id}/summary', [C::class, 'summary'])->whereNumber('id');
        Route::get('/students/{id}/timeline', [C::class, 'timeline'])->whereNumber('id');
        Route::post('/records', [C::class, 'store'])->middleware('throttle:60,1,post-api-behavior-records');
        Route::post('/records/{id}/void', [C::class, 'void'])->whereNumber('id')->middleware('throttle:60,1,post-api-behavior-void');
        Route::get('/export.csv', [C::class, 'export'])->middleware('throttle:20,1,get-api-behavior-export');
    });
    Route::middleware('role:admin,principal')->group(function () {
        Route::get('/report', [C::class, 'report']);
        Route::post('/rules', [C::class, 'createRule'])->middleware('throttle:30,1,post-api-behavior-rules');
        Route::put('/rules/{id}', [C::class, 'updateRule'])->whereNumber('id')->middleware('throttle:60,1,put-api-behavior-rules');
    });
});
