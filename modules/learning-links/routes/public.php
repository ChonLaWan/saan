<?php

// Public surface ('site'): classroom link page behind a short classroom code. Guesses are throttled per visitor.
use Illuminate\Support\Facades\Route;
use Saan\Modules\LearningLinks\Http\PublicController as P;

Route::get('/learn/{id}', [P::class, 'show'])->whereNumber('id')->middleware('throttle:60,1,get-learn-page');
Route::post('/learn/{id}', [P::class, 'unlock'])->whereNumber('id')->middleware('throttle:10,1,post-learn-code');