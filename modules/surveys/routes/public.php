<?php

// Public surface ('site'): no login. Submit is throttled per IP (a computer lab shares one IP, hence the generous limit);
// uniqueness comes from the verification link / roster code, not from the throttle. Staff API stays in routes/web.php (internal).
use Illuminate\Support\Facades\Route;
use Saan\Modules\Surveys\Http\SurveyPublicController as Pub;

Route::get('/portal/surveys/{slug}', [Pub::class, 'show'])->where('slug', '[a-z0-9-]{3,80}')->middleware('throttle:120,1,get-portal-survey-show');
Route::post('/portal/surveys/{slug}/submit', [Pub::class, 'submit'])->where('slug', '[a-z0-9-]{3,80}')->middleware('throttle:120,1,post-portal-survey-submit');
// GET only shows a button (mail scanners prefetch links); the POST behind it counts the response.
Route::get('/portal/surveys/{slug}/confirm/{pending}', [Pub::class, 'confirmForm'])->where('slug', '[a-z0-9-]{3,80}')->whereNumber('pending')
    ->middleware(['signed', 'throttle:60,1,get-portal-survey-confirm'])->name('surveys.confirm');
Route::post('/portal/surveys/{slug}/confirm/{pending}', [Pub::class, 'confirm'])->where('slug', '[a-z0-9-]{3,80}')->whereNumber('pending')
    ->middleware(['signed', 'throttle:60,1,post-portal-survey-confirm']);
// verify must be declared before {no}
Route::get('/portal/certificates/verify', [Pub::class, 'verify'])->middleware('throttle:60,1,get-portal-cert-verify');
Route::get('/portal/certificates/{no}', [Pub::class, 'certificate'])->where('no', '[A-Za-z0-9-]{8,60}')->middleware('throttle:60,1,get-portal-cert-view');