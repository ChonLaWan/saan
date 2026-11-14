<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\ExamChecker\Http\ExamController;
use Saan\Modules\ExamChecker\Http\ReportController;
use Saan\Modules\ExamChecker\Http\SheetController;

// Teachers (own exams and open exams, sheets of their classrooms), admin and principal (everything).
Route::middleware(['auth', CurrentSchoolAccount::class, 'role:admin,principal,teacher'])->group(function () {
    Route::prefix('api/exam-checker')->group(function () {
        Route::get('/exams', [ExamController::class, 'index']);
        Route::get('/subjects', [ExamController::class, 'subjects']);
        Route::post('/exams', [ExamController::class, 'store'])->middleware('throttle:60,1,post-api-exam-checker-exams');
        Route::get('/exams/{id}', [ExamController::class, 'show'])->whereNumber('id');
        Route::put('/exams/{id}', [ExamController::class, 'update'])->whereNumber('id')->middleware('throttle:60,1,put-api-exam-checker-exams');
        Route::delete('/exams/{id}', [ExamController::class, 'destroy'])->whereNumber('id')->middleware('throttle:30,1,delete-api-exam-checker-exams');
        Route::post('/exams/{id}/rescore', [ExamController::class, 'rescore'])->whereNumber('id')->middleware('throttle:20,1,post-api-exam-checker-rescore');
        Route::get('/exams/{id}/classrooms', [ExamController::class, 'classrooms'])->whereNumber('id');
        Route::get('/exams/{id}/roster', [ExamController::class, 'roster'])->whereNumber('id');

        Route::get('/exams/{id}/sheets', [SheetController::class, 'index'])->whereNumber('id');
        Route::post('/exams/{id}/sheets', [SheetController::class, 'store'])->whereNumber('id')->middleware('throttle:240,1,post-api-exam-checker-sheets');
        Route::post('/exams/{id}/import', [SheetController::class, 'import'])->whereNumber('id')->middleware('throttle:20,1,post-api-exam-checker-import');
        Route::put('/sheets/{id}', [SheetController::class, 'update'])->whereNumber('id')->middleware('throttle:240,1,put-api-exam-checker-sheets');
        Route::delete('/sheets/{id}', [SheetController::class, 'destroy'])->whereNumber('id')->middleware('throttle:120,1,delete-api-exam-checker-sheets');

        Route::get('/exams/{id}/analysis', [ReportController::class, 'analysis'])->whereNumber('id')->middleware('throttle:60,1,get-api-exam-checker-analysis');
        Route::get('/exams/{id}/summary', [ReportController::class, 'summary'])->whereNumber('id')->middleware('throttle:60,1,get-api-exam-checker-summary');
        Route::get('/exams/{id}/export', [ReportController::class, 'export'])->whereNumber('id')->middleware('throttle:20,1,get-api-exam-checker-export');
    });
    Route::get('/exam-checker/exams/{id}/slips', [ReportController::class, 'slips'])->whereNumber('id')->middleware('throttle:20,1,get-exam-checker-slips');
});
