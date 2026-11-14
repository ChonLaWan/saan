<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\Surveys\Http\SurveyAdminController as Admin;

// Public pages (/portal/surveys*, /portal/certificates*) live in routes/public.php (the 'site' surface).

// S5: in the internal edition the verification mail is sent by the internal side (after the pull), so its signed link opens here, on the internal host.
// Same two-step confirm as the /portal link (GET shows a button, POST counts the response); the signature is the credential, no sign-in.
Route::get('/survey-confirm/{slug}/{pending}', [\Saan\Modules\Surveys\Http\SurveyPublicController::class, 'confirmForm'])->where('slug', '[a-z0-9-]{3,80}')->whereNumber('pending')
    ->middleware(['signed', 'throttle:60,1,get-survey-confirm-internal'])->name('surveys.confirm.internal');
Route::post('/survey-confirm/{slug}/{pending}', [\Saan\Modules\Surveys\Http\SurveyPublicController::class, 'confirm'])->where('slug', '[a-z0-9-]{3,80}')->whereNumber('pending')
    ->middleware(['signed', 'throttle:60,1,post-survey-confirm-internal']);

Route::middleware(['auth', CurrentSchoolAccount::class, 'role:admin,principal,editor'])->group(function () {
    Route::get('/api/surveys', [Admin::class, 'index']);
    Route::post('/api/surveys', [Admin::class, 'create'])->middleware('throttle:30,1,post-api-surveys');
    Route::get('/api/surveys/{id}', [Admin::class, 'read'])->whereNumber('id');
    Route::put('/api/surveys/{id}', [Admin::class, 'save'])->whereNumber('id')->middleware('throttle:60,1,put-api-surveys');
    Route::delete('/api/surveys/{id}', [Admin::class, 'destroy'])->whereNumber('id')->middleware('throttle:20,1,delete-api-surveys');
    Route::post('/api/surveys/{id}/status', [Admin::class, 'status'])->whereNumber('id')->middleware('throttle:30,1,post-api-surveys-status');
    Route::get('/api/surveys/{id}/results', [Admin::class, 'results'])->whereNumber('id');
    Route::get('/api/surveys/{id}/export', [Admin::class, 'export'])->whereNumber('id')->middleware('throttle:20,1,get-api-surveys-export');
    Route::delete('/api/surveys/{id}/responses/{rid}', [Admin::class, 'destroyResponse'])->whereNumber('id')->whereNumber('rid')->middleware('throttle:60,1,delete-api-surveys-response');
    Route::post('/api/surveys/{id}/purge', [Admin::class, 'purgeResponses'])->whereNumber('id')->middleware('throttle:10,1,post-api-surveys-purge');
    Route::get('/api/surveys/{id}/roster', [Admin::class, 'roster'])->whereNumber('id');
    Route::post('/api/surveys/{id}/roster', [Admin::class, 'importRoster'])->whereNumber('id')->middleware('throttle:20,1,post-api-surveys-roster');
});
