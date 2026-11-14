<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\Maintenance\Http\MaintenanceController as M;

// Every signed-in staff role may reach the API; technician / manager rights are checked in Maintenance.
Route::middleware(['auth', CurrentSchoolAccount::class, 'role:admin,principal,teacher,editor'])->group(function () {
    Route::get('/api/maintenance/meta', [M::class, 'meta']);
    Route::get('/api/maintenance/technicians', [M::class, 'technicians']);
    Route::put('/api/maintenance/technicians', [M::class, 'setTechnicians'])->middleware('throttle:20,1,put-api-maintenance-technicians');

    Route::get('/api/maintenance/teachers', [M::class, 'teachers']);

    Route::get('/api/maintenance/requests',[M::class, 'requests']);
    Route::post('/api/maintenance/requests', [M::class, 'createRequest'])->middleware('throttle:30,1,post-api-maintenance-requests');
    Route::get('/api/maintenance/requests/{id}', [M::class, 'showRequest'])->whereNumber('id');
    Route::put('/api/maintenance/requests/{id}', [M::class, 'updateRequest'])->whereNumber('id')->middleware('throttle:60,1,put-api-maintenance-requests');
    Route::post('/api/maintenance/requests/{id}/transition', [M::class, 'transition'])->whereNumber('id')->middleware('throttle:60,1,post-api-maintenance-transition');
    Route::post('/api/maintenance/requests/{id}/assign', [M::class, 'assign'])->whereNumber('id')->middleware('throttle:60,1,post-api-maintenance-assign');
    Route::get('/api/maintenance/requests/{id}/photo', [M::class, 'photo'])->whereNumber('id')->middleware('throttle:240,1,get-api-maintenance-photo');

    Route::get('/api/maintenance/assets/lookup', [M::class, 'lookup'])->middleware('throttle:120,1,get-api-maintenance-lookup');
    Route::get('/api/maintenance/assets', [M::class, 'assets']);
    Route::post('/api/maintenance/assets', [M::class, 'createAsset'])->middleware('throttle:60,1,post-api-maintenance-assets');
    Route::get('/api/maintenance/assets/{id}', [M::class, 'showAsset'])->whereNumber('id');
    Route::put('/api/maintenance/assets/{id}', [M::class, 'updateAsset'])->whereNumber('id')->middleware('throttle:60,1,put-api-maintenance-assets');
    Route::delete('/api/maintenance/assets/{id}', [M::class, 'deleteAsset'])->whereNumber('id')->middleware('throttle:30,1,delete-api-maintenance-assets');

    Route::get('/api/maintenance/stock', [M::class, 'stock']);
    Route::post('/api/maintenance/stock', [M::class, 'createItem'])->middleware('throttle:60,1,post-api-maintenance-stock');
    Route::put('/api/maintenance/stock/{id}', [M::class, 'updateItem'])->whereNumber('id')->middleware('throttle:60,1,put-api-maintenance-stock');
    Route::get('/api/maintenance/stock/{id}/moves', [M::class, 'moves'])->whereNumber('id');
    Route::post('/api/maintenance/stock/{id}/moves', [M::class, 'move'])->whereNumber('id')->middleware('throttle:120,1,post-api-maintenance-moves');

    Route::get('/api/maintenance/reports/open-by-age', [M::class, 'openByAge']);
    Route::get('/api/maintenance/reports/cost-by-month', [M::class, 'costByMonth']);
    Route::get('/api/maintenance/reports/stock-below', [M::class, 'stockBelow']);
    Route::get('/api/maintenance/export/{what}', [M::class, 'export'])->middleware('throttle:20,1,get-api-maintenance-export');
    Route::get('/maintenance/labels', [M::class, 'labels'])->middleware('throttle:30,1,get-maintenance-labels');
});
