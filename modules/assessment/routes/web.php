<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\Assessment\Http\AssessmentController as A;

// Reads and score writes: admin/principal/teacher (teachers only reach courses assigned to them, checked in Assessment::course()).
// Setup writes (subjects, courses): admin/principal.
Route::middleware(['auth', CurrentSchoolAccount::class])->prefix('api/assessment')->group(function () {
    Route::middleware('role:admin,principal,teacher')->group(function () {
        Route::get('/terms', [A::class, 'terms']);
        Route::get('/subjects', [A::class, 'subjects']);
        Route::get('/courses', [A::class, 'courses']);
        Route::get('/history/courses', [A::class, 'history']);
        Route::get('/courses/{id}/scores', [A::class, 'scores'])->whereNumber('id');
        Route::put('/courses/{id}/scores', [A::class, 'scoresSave'])->whereNumber('id')->middleware('throttle:60,1,put-api-assessment-scores');
        Route::get('/courses/{id}/revisions', [A::class, 'revisions'])->whereNumber('id');
        Route::get('/courses/{id}/attributes', [A::class, 'attributes'])->whereNumber('id');
        Route::put('/courses/{id}/attributes', [A::class, 'attributesSave'])->whereNumber('id')->middleware('throttle:60,1,put-api-assessment-attributes');
        // term-level attributes: current term only, classroom_id in the query/body; teachers limited to their homeroom (checked in Assessment)
        Route::get('/terms/current/attributes', [A::class, 'termAttributes']);
        Route::put('/terms/current/attributes', [A::class, 'termAttributesSave'])->middleware('throttle:60,1,put-api-assessment-term-attributes');
        Route::delete('/courses/{id}/attributes', [A::class, 'attributesDelete'])->whereNumber('id')->middleware('throttle:30,1,delete-api-assessment-attributes');
        Route::delete('/terms/current/attributes', [A::class, 'termAttributesDelete'])->middleware('throttle:30,1,delete-api-assessment-term-attributes');
    });

    Route::middleware('role:admin,principal')->group(function () {
        Route::get('/lookups', [A::class, 'lookups']);
        Route::post('/subjects', [A::class, 'subjectCreate'])->middleware('throttle:30,1,post-api-assessment-subjects');
        Route::put('/subjects/{id}', [A::class, 'subjectUpdate'])->whereNumber('id')->middleware('throttle:60,1,put-api-assessment-subjects');
        Route::delete('/subjects/{id}', [A::class, 'subjectDelete'])->whereNumber('id')->middleware('throttle:30,1,delete-api-assessment-subjects');
        Route::post('/courses', [A::class, 'courseCreate'])->middleware('throttle:60,1,post-api-assessment-courses');
        Route::post('/courses/bulk', [A::class, 'courseBulk'])->middleware('throttle:20,1,post-api-assessment-courses-bulk');
        Route::post('/courses/copy', [A::class, 'courseCopy'])->middleware('throttle:10,1,post-api-assessment-courses-copy');
        Route::put('/courses/{id}', [A::class, 'courseUpdate'])->whereNumber('id')->middleware('throttle:60,1,put-api-assessment-courses');
        Route::delete('/courses/{id}', [A::class, 'courseDelete'])->whereNumber('id')->middleware('throttle:30,1,delete-api-assessment-courses');
    });
});
