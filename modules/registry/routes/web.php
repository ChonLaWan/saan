<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\Registry\Http\DocumentController;
use Saan\Modules\Registry\Http\ImportController;
use Saan\Modules\Registry\Http\RegistryController;

// Registrars: admin and principal. Teachers read their own homeroom students only (no identifiers, no documents).
Route::middleware(['auth', CurrentSchoolAccount::class])->prefix('api/registry')->group(function () {
    Route::middleware('role:admin,principal,teacher')->group(function () {
        Route::get('/classrooms', [RegistryController::class, 'classrooms']);
        Route::get('/students', [RegistryController::class, 'index']);
        Route::get('/students/{id}', [RegistryController::class, 'show'])->whereNumber('id');
        Route::get('/export/class-list', [RegistryController::class, 'exportClass'])->middleware('throttle:20,1,get-api-registry-export');
    });

    Route::middleware('role:admin,principal')->group(function () {
        Route::post('/students', [RegistryController::class, 'store'])->middleware('throttle:60,1,post-api-registry-students');
        Route::put('/students/{id}', [RegistryController::class, 'update'])->whereNumber('id')->middleware('throttle:120,1,put-api-registry-students');
        Route::post('/students/{id}/guardians', [RegistryController::class, 'addGuardian'])->whereNumber('id')->middleware('throttle:60,1,post-api-registry-guardians');
        Route::put('/guardians/{id}', [RegistryController::class, 'updateGuardian'])->whereNumber('id')->middleware('throttle:120,1,put-api-registry-guardians');
        Route::get('/guardians/unlinked', [RegistryController::class, 'unlinkedGuardians']);
        Route::delete('/guardians/{id}', [RegistryController::class, 'deleteGuardian'])->whereNumber('id')->middleware('throttle:30,1,delete-api-registry-guardians');
        Route::put('/student-guardians/{id}', [RegistryController::class, 'updateLink'])->whereNumber('id')->middleware('throttle:120,1,put-api-registry-links');
        Route::delete('/student-guardians/{id}', [RegistryController::class, 'deleteLink'])->whereNumber('id')->middleware('throttle:60,1,delete-api-registry-links');

        Route::post('/identifiers', [RegistryController::class, 'addIdentifier'])->middleware('throttle:60,1,post-api-registry-identifiers');
        Route::put('/identifiers/{id}', [RegistryController::class, 'updateIdentifier'])->whereNumber('id')->middleware('throttle:60,1,put-api-registry-identifiers');
        Route::delete('/identifiers/{id}', [RegistryController::class, 'deleteIdentifier'])->whereNumber('id')->middleware('throttle:30,1,delete-api-registry-identifiers');
        Route::post('/identifiers/{id}/reveal', [RegistryController::class, 'reveal'])->whereNumber('id')->middleware('throttle:30,1,post-api-registry-reveal');

        Route::post('/students/{id}/documents', [DocumentController::class, 'store'])->whereNumber('id')->middleware('throttle:30,1,post-api-registry-documents');
        Route::get('/documents/{id}/download', [DocumentController::class, 'download'])->whereNumber('id')->middleware('throttle:60,1,get-api-registry-download');
        Route::put('/documents/{id}', [DocumentController::class, 'update'])->whereNumber('id')->middleware('throttle:60,1,put-api-registry-documents');
        Route::delete('/documents/{id}', [DocumentController::class, 'destroy'])->whereNumber('id')->middleware('throttle:30,1,delete-api-registry-documents');

        Route::get('/import/batches', [ImportController::class, 'index']);
        Route::post('/import/batches', [ImportController::class, 'store'])->middleware('throttle:10,1,post-api-registry-import');
        Route::get('/import/batches/{id}', [ImportController::class, 'show'])->whereNumber('id');
        Route::post('/import/batches/{id}/commit', [ImportController::class, 'commit'])->whereNumber('id')->middleware('throttle:10,1,post-api-registry-import-commit');
        Route::post('/import/batches/{id}/rollback', [ImportController::class, 'rollback'])->whereNumber('id')->middleware('throttle:10,1,post-api-registry-import-rollback');
    });
});
