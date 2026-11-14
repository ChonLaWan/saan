<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\Constructionism\Http\ProjectController as P;

// Teacher-only entry: admin, principal and teachers. Students have no login here. Rubric edits: admin and principal.
Route::middleware(['auth', CurrentSchoolAccount::class, 'role:admin,principal,teacher'])->group(function () {
    Route::get('/pbl/projects/{id}/print', [P::class, 'print'])->whereNumber('id')->middleware('throttle:60,1,get-pbl-print');

    Route::prefix('api/pbl')->group(function () {
        Route::get('/options', [P::class, 'options']);
        Route::get('/projects', [P::class, 'index']);
        Route::post('/projects', [P::class, 'store'])->middleware('throttle:30,1,post-api-pbl-projects');
        Route::get('/projects/{id}', [P::class, 'show'])->whereNumber('id');
        Route::put('/projects/{id}', [P::class, 'update'])->whereNumber('id')->middleware('throttle:60,1,put-api-pbl-projects');
        Route::delete('/projects/{id}', [P::class, 'destroy'])->whereNumber('id')->middleware('throttle:20,1,delete-api-pbl-projects');

        Route::post('/projects/{id}/members', [P::class, 'memberStore'])->whereNumber('id')->middleware('throttle:60,1,post-api-pbl-members');
        Route::put('/members/{id}', [P::class, 'memberUpdate'])->whereNumber('id')->middleware('throttle:60,1,put-api-pbl-members');
        Route::delete('/members/{id}', [P::class, 'memberDestroy'])->whereNumber('id')->middleware('throttle:60,1,delete-api-pbl-members');

        Route::post('/projects/{id}/logs', [P::class, 'logStore'])->whereNumber('id')->middleware('throttle:60,1,post-api-pbl-logs');
        Route::put('/logs/{id}', [P::class, 'logUpdate'])->whereNumber('id')->middleware('throttle:60,1,put-api-pbl-logs');
        Route::delete('/logs/{id}', [P::class, 'logDestroy'])->whereNumber('id')->middleware('throttle:60,1,delete-api-pbl-logs');

        Route::post('/projects/{id}/artifacts', [P::class, 'artifactStore'])->whereNumber('id')->middleware('throttle:30,1,post-api-pbl-artifacts');
        Route::put('/artifacts/{id}', [P::class, 'artifactUpdate'])->whereNumber('id')->middleware('throttle:60,1,put-api-pbl-artifacts');
        Route::delete('/artifacts/{id}', [P::class, 'artifactDestroy'])->whereNumber('id')->middleware('throttle:60,1,delete-api-pbl-artifacts');
        Route::get('/artifacts/{id}/file', [P::class, 'artifactFile'])->whereNumber('id')->middleware('throttle:60,1,get-api-pbl-artifact-file');

        Route::post('/projects/{id}/reflections', [P::class, 'reflectionStore'])->whereNumber('id')->middleware('throttle:60,1,post-api-pbl-reflections');
        Route::put('/reflections/{id}', [P::class, 'reflectionUpdate'])->whereNumber('id')->middleware('throttle:60,1,put-api-pbl-reflections');
        Route::delete('/reflections/{id}', [P::class, 'reflectionDestroy'])->whereNumber('id')->middleware('throttle:60,1,delete-api-pbl-reflections');

        Route::get('/rubric', [P::class, 'rubric']);
        Route::put('/rubric', [P::class, 'rubricSave'])->middleware(['role:admin,principal', 'throttle:20,1,put-api-pbl-rubric']);
    });
});
