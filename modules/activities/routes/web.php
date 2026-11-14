<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\Activities\Http\ClubController as C;
use Saan\Modules\Activities\Http\ElectionController as E;

// Staff: admin, principal, teacher. Club writes need the advisor or admin/principal (checked in Clubs::requireManage).
// Election setup, opening and closing are admin/principal; teachers unlock the kiosk, watch turnout and read results after close.
Route::middleware(['auth', CurrentSchoolAccount::class, 'role:admin,principal,teacher'])->group(function () {
    Route::prefix('api/activities')->group(function () {
        Route::get('/clubs', [C::class, 'index']);
        Route::get('/clubs/{id}', [C::class, 'show'])->whereNumber('id');
        Route::get('/settings', [C::class, 'settings']);
        Route::get('/classrooms', [C::class, 'classrooms']);
        Route::get('/classrooms/{id}/students', [C::class, 'classroomStudents'])->whereNumber('id');
        Route::post('/clubs/{id}/enroll', [C::class, 'enroll'])->whereNumber('id')->middleware('throttle:60,1,post-api-activities-enroll');
        Route::post('/members/{id}/leave', [C::class, 'leave'])->whereNumber('id')->middleware('throttle:60,1,post-api-activities-leave');
        Route::post('/clubs/{id}/sessions', [C::class, 'sessionStore'])->whereNumber('id')->middleware('throttle:60,1,post-api-activities-sessions');
        Route::put('/sessions/{id}', [C::class, 'sessionUpdate'])->whereNumber('id')->middleware('throttle:60,1,put-api-activities-sessions');
        Route::delete('/sessions/{id}', [C::class, 'sessionDestroy'])->whereNumber('id')->middleware('throttle:30,1,delete-api-activities-sessions');
        Route::get('/sessions/{id}/attendance', [C::class, 'attendance'])->whereNumber('id');
        Route::put('/sessions/{id}/attendance', [C::class, 'attendanceSave'])->whereNumber('id')->middleware('throttle:60,1,put-api-activities-attendance');
        Route::post('/clubs/{id}/results/compute', [C::class, 'compute'])->whereNumber('id')->middleware('throttle:30,1,post-api-activities-compute');
        Route::put('/members/{id}/result', [C::class, 'override'])->whereNumber('id')->middleware('throttle:60,1,put-api-activities-result');
        Route::get('/clubs/{id}/certificates', [C::class, 'certificates'])->whereNumber('id');
        Route::post('/clubs/{id}/certificates', [C::class, 'certificatesIssue'])->whereNumber('id')->middleware('throttle:20,1,post-api-activities-certificates');

        Route::get('/elections', [E::class, 'index']);
        Route::get('/elections/{id}', [E::class, 'show'])->whereNumber('id');
        Route::get('/elections/{id}/turnout', [E::class, 'turnout'])->whereNumber('id')->middleware('throttle:120,1,get-api-activities-turnout');
        Route::get('/elections/{id}/results', [E::class, 'results'])->whereNumber('id');
        Route::get('/elections/{id}/kiosk/{classroomId}', [E::class, 'kiosk'])->whereNumber('id')->whereNumber('classroomId')->middleware('throttle:120,1,get-api-activities-kiosk');
        Route::post('/elections/{id}/unlock', [E::class, 'unlock'])->whereNumber('id')->middleware('throttle:240,1,post-api-activities-unlock');
        Route::post('/elections/{id}/vote', [E::class, 'vote'])->whereNumber('id')->middleware('throttle:240,1,post-api-activities-vote');

        Route::middleware('role:admin,principal')->group(function () {
            Route::post('/clubs', [C::class, 'store'])->middleware('throttle:30,1,post-api-activities-clubs');
            Route::put('/clubs/{id}', [C::class, 'update'])->whereNumber('id')->middleware('throttle:60,1,put-api-activities-clubs');
            Route::delete('/clubs/{id}', [C::class, 'destroy'])->whereNumber('id')->middleware('throttle:20,1,delete-api-activities-clubs');
            Route::get('/students/lookup', [C::class, 'studentLookup'])->middleware('throttle:120,1,get-api-activities-student-lookup');
            Route::put('/settings',[C::class, 'settingsSave'])->middleware('throttle:20,1,put-api-activities-settings');

            Route::post('/elections', [E::class, 'store'])->middleware('throttle:20,1,post-api-activities-elections');
            Route::put('/elections/{id}', [E::class, 'update'])->whereNumber('id')->middleware('throttle:30,1,put-api-activities-elections');
            Route::delete('/elections/{id}', [E::class, 'destroy'])->whereNumber('id')->middleware('throttle:20,1,delete-api-activities-elections');
            Route::post('/elections/{id}/status', [E::class, 'status'])->whereNumber('id')->middleware('throttle:30,1,post-api-activities-election-status');
            Route::post('/elections/{id}/candidates', [E::class, 'candidateStore'])->whereNumber('id')->middleware('throttle:60,1,post-api-activities-candidates');
            Route::delete('/candidates/{id}', [E::class, 'candidateDestroy'])->whereNumber('id')->middleware('throttle:60,1,delete-api-activities-candidates');
        });
    });

    Route::get('/activities/clubs/{id}/certificates/print', [C::class, 'certificatesPrint'])->whereNumber('id')->middleware('throttle:30,1,get-activities-certificates-print');
});
