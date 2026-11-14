<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\Timetable\Http\TimetableController as T;

// Reads: admin, principal, teacher (teacher scope is enforced in Timetable). Writes: admin, principal.
Route::middleware(['auth', CurrentSchoolAccount::class])->group(function () {
    Route::middleware('role:admin,principal,teacher')->group(function () {
        Route::get('/api/timetable/meta', [T::class, 'meta']);
        Route::get('/api/timetable/lessons', [T::class, 'lessons']);
        Route::get('/api/timetable/load', [T::class, 'load']);
        Route::get('/timetable/print/{type}/{id}', [T::class, 'print'])->whereIn('type', ['classroom', 'teacher'])->whereNumber('id');
    });

    Route::middleware('role:admin,principal')->prefix('api/timetable')->group(function () {
        Route::get('/trash', [T::class, 'trash']);
        Route::post('/lessons', [T::class, 'create'])->middleware('throttle:60,1,post-api-timetable-lessons');
        Route::put('/lessons/{id}', [T::class, 'update'])->whereNumber('id')->middleware('throttle:120,1,put-api-timetable-lessons');
        Route::delete('/lessons/{id}', [T::class, 'destroy'])->whereNumber('id')->middleware('throttle:60,1,delete-api-timetable-lessons');
        Route::post('/lessons/{id}/restore', [T::class, 'restore'])->whereNumber('id')->middleware('throttle:60,1,post-api-timetable-restore');
        Route::post('/copy/preview', [T::class, 'copyPreview'])->middleware('throttle:30,1,post-api-timetable-copy-preview');
        Route::post('/copy', [T::class, 'copyCommit'])->middleware('throttle:10,1,post-api-timetable-copy');
    });
});
