<?php

// Public surface ('site'): public-safe staff list for themes/web-cms and the public staff photo derivative. Staff screens stay in routes/web.php.
use Illuminate\Support\Facades\Route;
use Saan\Modules\Personnel\Http\StaffController;

// G3: no login, no private fields, cacheable.
Route::get('/api/personnel/directory', [StaffController::class, 'directory'])->middleware('throttle:60,1,get-api-personnel-directory');

// Staff photos are streamed from the private disk after an access check (no public/storage symlink involved).
Route::get('/portal/staff-photo/{file}', [StaffController::class, 'photoFile'])->where('file', '[0-9a-f]{32}\.jpg')->middleware('throttle:240,1,get-portal-staff-photo');