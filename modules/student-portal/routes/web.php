<?php

use App\Http\Middleware\CurrentSchoolAccount;
use App\Support\Saan\Edition;
use Illuminate\Support\Facades\Route;
use Saan\Modules\GuardianPortal\Http\NoIndex;
use Saan\Modules\StudentPortal\Http\StaffController as S;
use Saan\Modules\StudentPortal\Http\StudentController as C;
use Saan\Modules\StudentPortal\Http\StudentSession;

// Student side: no staff login. POST /student/login has only a very high outer wall per address (a class shares one school address): the failure counters in StudentController::login and Pins::attempt are the real guard;
// The API budget is per signed-in student (StudentSession), never per address.
// Access = student code + secret issued by the homeroom teacher (see Credentials / Access).
// Edition: the 'student' surface is internal-only; the public edition never registers it (and module.json says edition internal).
if (Edition::serves('student')) {
    Route::prefix('student')->middleware(NoIndex::class)->group(function () {
        Route::get('/', [C::class, 'shell']);
        Route::post('/login', [C::class, 'login'])->middleware('throttle:600,1,post-student-login');
        Route::post('/logout', [C::class, 'logout'])->middleware('throttle:60,1,post-student-logout');
        Route::get('/forgot', [C::class, 'forgotPage'])->middleware('throttle:60,1,get-student-forgot');
        Route::post('/forgot', [C::class, 'forgot'])->middleware('throttle:10,1,post-student-forgot');
        Route::get('/reset/{token}', [C::class, 'resetPage'])->where('token', '[A-Za-z0-9_-]{20,200}')->middleware('throttle:60,1,get-student-reset');
        Route::post('/reset', [C::class, 'reset'])->middleware('throttle:20,1,post-student-reset');
        Route::get('/verify/{token}', [C::class, 'verifyPage'])->where('token', '[A-Za-z0-9_-]{20,64}')->middleware('throttle:60,1,get-student-verify');
        Route::post('/verify/{token}', [C::class, 'verify'])->where('token', '[A-Za-z0-9_-]{20,64}')->middleware('throttle:30,1,post-student-verify');

        // read-only own data: none of these takes a student id
        Route::middleware(StudentSession::class)->prefix('api')->group(function () {
            Route::group([], function () {
                Route::get('/me', [C::class, 'me']);
                Route::get('/report', [C::class, 'report']);
                Route::get('/timetable', [C::class, 'timetable']);
                Route::get('/attendance', [C::class, 'attendance']);
                Route::get('/behavior', [C::class, 'behavior']);
            });
            Route::post('/secret', [C::class, 'secret'])->middleware('throttle:20,1,post-student-secret');
            Route::post('/email', [C::class, 'email'])->middleware('throttle:10,1,post-student-email');
        });
    });
}

// Staff side (work page #student-accounts): admin/principal everywhere, teacher only the homeroom (checked in the controller on every id).
Route::middleware(['auth', CurrentSchoolAccount::class, 'role:admin,principal,teacher'])->prefix('api/student-portal')->group(function () {
    Route::get('/context', [S::class, 'context']);
    Route::get('/classrooms/{id}/accounts', [S::class, 'accounts'])->whereNumber('id');
    Route::post('/classrooms/{id}/issue', [S::class, 'issueClass'])->whereNumber('id')->middleware('throttle:20,1,post-api-student-portal-issue');
    Route::post('/students/{id}/reset', [S::class, 'reset'])->whereNumber('id')->middleware('throttle:60,1,post-api-student-portal-reset');
    Route::post('/students/{id}/unlock', [S::class, 'unlock'])->whereNumber('id')->middleware('throttle:60,1,post-api-student-portal-unlock');
    Route::post('/students/{id}/guardians/{guardianId}/issue', [S::class, 'issueGuardian'])->whereNumber(['id', 'guardianId'])->middleware('throttle:60,1,post-api-student-portal-guardian-issue');
    Route::post('/guardian-accounts/{guardianId}/unlock', [S::class, 'unlockGuardian'])->whereNumber('guardianId')->middleware('throttle:60,1,post-api-student-portal-guardian-unlock');
});
