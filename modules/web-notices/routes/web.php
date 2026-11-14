<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\WebNotices\Http\NoticeController;

// Console command and schedule are intentionally NOT registered here (routes load only for active modules and
// are skipped under route:cache). See Console/PruneCommand.php for what core must register.

// Public pages (/portal/notices*) live in routes/public.php (the 'site' surface).

Route::middleware(['auth', CurrentSchoolAccount::class, 'role:admin,principal,editor'])->group(function () {
    Route::get('/api/notices/lists', [NoticeController::class, 'index']);
    Route::post('/api/notices/lists', [NoticeController::class, 'create'])->middleware('throttle:30,1,post-api-notices-create');
    Route::get('/api/notices/lists/{id}', [NoticeController::class, 'read'])->whereNumber('id');
    Route::put('/api/notices/lists/{id}', [NoticeController::class, 'update'])->whereNumber('id')->middleware('throttle:60,1,put-api-notices-update');
    Route::put('/api/notices/lists/{id}/rows', [NoticeController::class, 'rows'])->whereNumber('id')->middleware('throttle:30,1,put-api-notices-rows');
    Route::post('/api/notices/lists/{id}/status', [NoticeController::class, 'status'])->whereNumber('id')->middleware('throttle:30,1,post-api-notices-status');
    Route::delete('/api/notices/lists/{id}', [NoticeController::class, 'destroy'])->whereNumber('id')->middleware('throttle:20,1,delete-api-notices');
});
