<?php

use App\Http\Middleware\CurrentSchoolAccount;
use App\Support\Saan\Edition;
use Illuminate\Support\Facades\Route;
use Saan\Modules\Personnel\Http\PortfolioController;
use Saan\Modules\Personnel\Http\StaffController;

// The public directory API and the public staff photo live in routes/public.php (the 'site' surface).
// Staff screens (admin/principal) still show photos, so an edition without the public site serves the same file behind a sign-in
// (StaffController::photoFile already gives staff the private variant and guests only the public derivative).
if (!Edition::serves('site')) {
    Route::get('/portal/staff-photo/{file}', [StaffController::class, 'photoFile'])->where('file', '[0-9a-f]{32}\.jpg')->middleware(['auth', CurrentSchoolAccount::class, 'throttle:240,1,get-portal-staff-photo']);
}

Route::middleware(['auth', CurrentSchoolAccount::class])->prefix('api/personnel')->group(function () {
    // teachers see and change only their own portfolio; admin/principal reach every teacher (checked again in Files::find)
    Route::middleware('role:teacher,admin,principal')->group(function () {
        Route::get('/me', [PortfolioController::class, 'me']);
        Route::get('/folders', [PortfolioController::class, 'folders']);
        Route::get('/files', [PortfolioController::class, 'index']);
        Route::post('/files', [PortfolioController::class, 'store'])->middleware('throttle:60,1,post-api-personnel-files');
        Route::get('/files/{id}/download', [PortfolioController::class, 'download'])->whereNumber('id')->middleware('throttle:120,1,get-api-personnel-download');
        Route::delete('/files/{id}', [PortfolioController::class, 'destroy'])->whereNumber('id')->middleware('throttle:60,1,delete-api-personnel-files');
        Route::get('/teachers/{teacherId}/zip', [PortfolioController::class, 'zip'])->whereNumber('teacherId')->middleware('throttle:10,1,get-api-personnel-zip');
    });

    Route::middleware('role:admin,principal')->group(function () {
        Route::get('/summary', [StaffController::class, 'summary']);
        Route::get('/staff', [StaffController::class, 'index']);
        Route::get('/staff/{teacherId}', [StaffController::class, 'show'])->whereNumber('teacherId');
        Route::put('/staff/{teacherId}', [StaffController::class, 'save'])->whereNumber('teacherId')->middleware('throttle:120,1,put-api-personnel-staff');
        Route::post('/staff/{teacherId}/photo', [StaffController::class, 'photo'])->whereNumber('teacherId')->middleware('throttle:30,1,post-api-personnel-photo');
        Route::delete('/staff/{teacherId}/photo', [StaffController::class, 'removePhoto'])->whereNumber('teacherId')->middleware('throttle:30,1,delete-api-personnel-photo');
        Route::put('/files/{id}/review', [PortfolioController::class, 'review'])->whereNumber('id')->middleware('throttle:240,1,put-api-personnel-review');
        Route::put('/settings', [StaffController::class, 'putSettings'])->middleware('throttle:20,1,put-api-personnel-settings');
        Route::post('/folders', [StaffController::class, 'createFolder'])->middleware('throttle:30,1,post-api-personnel-folders');
        Route::put('/folders/{id}', [StaffController::class, 'updateFolder'])->whereNumber('id')->middleware('throttle:60,1,put-api-personnel-folders');
        Route::delete('/folders/{id}', [StaffController::class, 'deleteFolder'])->whereNumber('id')->middleware('throttle:30,1,delete-api-personnel-folders');
    });
});
