<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\Connectors\Http\ConnectorController;

// Lookup: admin and principal always; teachers only when Settings 'connectors.teacher_lookup' is on (checked in ArchiveService::gate).
Route::middleware(['auth', CurrentSchoolAccount::class])->prefix('api/connectors')->group(function () {
    Route::middleware('role:admin,principal,teacher')->group(function () {
        Route::get('/config', [ConnectorController::class, 'config']);
        Route::get('/search', [ConnectorController::class, 'search'])->middleware('throttle:60,1,get-api-connectors-search');
        Route::get('/record', [ConnectorController::class, 'record'])->middleware('throttle:60,1,get-api-connectors-record');
        Route::get('/record/export', [ConnectorController::class, 'export'])->middleware('throttle:20,1,get-api-connectors-export');
    });

    Route::middleware('role:admin,principal')->group(function () {
        Route::get('/presets', [ConnectorController::class, 'presets']);
        Route::get('/archives', [ConnectorController::class, 'index']);
        Route::get('/archives/{id}', [ConnectorController::class, 'show'])->whereNumber('id');
        Route::post('/archives', [ConnectorController::class, 'store'])->middleware('throttle:30,1,post-api-connectors-archives');
        Route::put('/archives/{id}', [ConnectorController::class, 'update'])->whereNumber('id')->middleware('throttle:60,1,put-api-connectors-archives');
        Route::delete('/archives/{id}', [ConnectorController::class, 'destroy'])->whereNumber('id')->middleware('throttle:20,1,delete-api-connectors-archives');
        Route::post('/archives/{id}/files', [ConnectorController::class, 'import'])->whereNumber('id')->middleware('throttle:10,1,post-api-connectors-files');

        Route::post('/mapping/preview', [ConnectorController::class, 'mappingPreview'])->middleware('throttle:20,1,post-api-connectors-map-preview');
        Route::post('/mapping/csv', [ConnectorController::class, 'mappingCsv'])->middleware('throttle:20,1,post-api-connectors-map-csv');
        Route::get('/mapping/runs', [ConnectorController::class, 'mappingRuns']);
        Route::get('/mapping/runs/{id}', [ConnectorController::class, 'mappingRun'])->whereNumber('id');
        Route::post('/mapping/runs', [ConnectorController::class, 'mappingStage'])->middleware('throttle:10,1,post-api-connectors-map-stage');
        Route::post('/mapping/runs/{id}/commit', [ConnectorController::class, 'mappingCommit'])->whereNumber('id')->middleware('throttle:10,1,post-api-connectors-map-commit');
    });

    Route::put('/settings', [ConnectorController::class, 'setSettings'])->middleware(['role:admin', 'throttle:30,1,put-api-connectors-settings']);
});
