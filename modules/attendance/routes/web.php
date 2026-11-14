<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\Attendance\Http\AttendanceController as A;

// All routes: signed-in staff (teacher / admin / principal). Classroom access is further limited by TeacherScope in the service.
Route::middleware(['auth', CurrentSchoolAccount::class, 'role:teacher,admin,principal'])->prefix('api/attendance')->group(function () {
    Route::get('/classes', [A::class, 'classes']);
    Route::get('/sheet', [A::class, 'sheet']);
    Route::post('/sheet', [A::class, 'save'])->middleware('throttle:60,1,post-api-attendance-sheet');
    Route::get('/reports/month', [A::class, 'month']);
    Route::get('/reports/daily', [A::class, 'daily']);
    Route::get('/reports/student/{id}', [A::class, 'student'])->whereNumber('id');
    Route::get('/export', [A::class, 'export'])->middleware('throttle:30,1,get-api-attendance-export');
});
