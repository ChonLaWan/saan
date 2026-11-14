<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\Academic\Http\AcademicController as A;

// Reads: all staff (setup status and account data only for admin/principal). Writes: admin/principal; teacher accounts: admin only.
Route::middleware(['auth', CurrentSchoolAccount::class])->prefix('api/academic')->group(function () {
    Route::middleware('role:admin,principal')->get('/setup', [A::class, 'setup']);

    Route::middleware('role:admin,principal,teacher')->group(function () {
        Route::get('/terms', [A::class, 'terms']);
        Route::get('/periods', [A::class, 'periods']);
        Route::get('/classrooms', [A::class, 'classrooms']);
        Route::get('/teachers', [A::class, 'teachers']);
        Route::get('/calendar', [A::class, 'calendar']);
    });

    Route::middleware('role:admin,principal')->group(function () {
        Route::post('/terms', [A::class, 'termCreate'])->middleware('throttle:30,1,post-api-academic-terms');
        Route::put('/terms/{id}', [A::class, 'termUpdate'])->whereNumber('id')->middleware('throttle:60,1,put-api-academic-terms');
        Route::delete('/terms/{id}', [A::class, 'termDelete'])->whereNumber('id')->middleware('throttle:30,1,delete-api-academic-terms');
        Route::post('/terms/{id}/current', [A::class, 'termCurrent'])->whereNumber('id')->middleware('throttle:30,1,post-api-academic-terms-current');

        Route::put('/periods', [A::class, 'periodsSave'])->middleware('throttle:30,1,put-api-academic-periods');

        Route::post('/classrooms', [A::class, 'classroomCreate'])->middleware('throttle:60,1,post-api-academic-classrooms');
        Route::post('/classrooms/bulk', [A::class, 'classroomsBulk'])->middleware('throttle:20,1,post-api-academic-classrooms-bulk');
        Route::put('/classrooms/{id}', [A::class, 'classroomUpdate'])->whereNumber('id')->middleware('throttle:60,1,put-api-academic-classrooms');
        Route::delete('/classrooms/{id}', [A::class, 'classroomDelete'])->whereNumber('id')->middleware('throttle:30,1,delete-api-academic-classrooms');
        Route::put('/homerooms', [A::class, 'homerooms'])->middleware('throttle:30,1,put-api-academic-homerooms');

        Route::post('/teachers', [A::class, 'teacherCreate'])->middleware('throttle:60,1,post-api-academic-teachers');
        Route::post('/teachers/bulk', [A::class, 'teachersBulk'])->middleware('throttle:20,1,post-api-academic-teachers-bulk');
        Route::put('/teachers/{id}', [A::class, 'teacherUpdate'])->whereNumber('id')->middleware('throttle:60,1,put-api-academic-teachers');
        Route::delete('/teachers/{id}', [A::class, 'teacherDelete'])->whereNumber('id')->middleware('throttle:30,1,delete-api-academic-teachers');

        Route::post('/calendar', [A::class, 'dayCreate'])->middleware('throttle:60,1,post-api-academic-calendar');
        Route::put('/calendar/{id}', [A::class, 'dayUpdate'])->whereNumber('id')->middleware('throttle:60,1,put-api-academic-calendar');
        Route::delete('/calendar/{id}', [A::class, 'dayDelete'])->whereNumber('id')->middleware('throttle:30,1,delete-api-academic-calendar');
    });

    Route::post('/teachers/{id}/account', [A::class, 'teacherAccount'])->whereNumber('id')->middleware(['role:admin', 'throttle:10,1,post-api-academic-teacher-account']);
});
