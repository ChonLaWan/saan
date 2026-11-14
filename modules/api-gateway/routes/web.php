<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use App\Support\Saan\Edition;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Saan\Modules\ApiGateway\Http\AdminController;
use Saan\Modules\ApiGateway\Http\ApiAuth;
use Saan\Modules\ApiGateway\Http\OAuthController;
use Saan\Modules\ApiGateway\Http\PinController;
use Saan\Modules\ApiGateway\Http\ResourceController;
use Saan\Modules\ApiGateway\Http\TokenController;

// Machine endpoints: called server-to-server with a client secret or a bearer token, never from a browser session.
// They are stateless (no session, cookies or CSRF token); the secret/bearer is the credential. Rate limits live in the controllers.
$machine = [PreventRequestForgery::class, VerifyCsrfToken::class, StartSession::class, ShareErrorsFromSession::class, EncryptCookies::class, AddQueuedCookiesToResponse::class];

// Edition: the 'api' surface (/api/v1*, /oauth*) is internal-only; the public edition never registers it.
if (Edition::serves('api')) {
    Route::withoutMiddleware($machine)->group(function () {
        Route::post('/api/v1/token', [TokenController::class, 'token']);
        Route::post('/oauth/token', [TokenController::class, 'token']);

        $v1 = fn (string $scope) => ApiAuth::class.':'.$scope;
        Route::middleware($v1('students:read:basic'))->get('/api/v1/students', [ResourceController::class, 'students']);
        Route::middleware($v1('classrooms:read'))->get('/api/v1/classrooms', [ResourceController::class, 'classrooms']);
        Route::middleware($v1('teachers:read:basic'))->get('/api/v1/teachers', [ResourceController::class, 'teachers']);
        Route::middleware($v1('courses:read'))->get('/api/v1/courses', [ResourceController::class, 'courses']);
        Route::middleware($v1('attendance:read:summary'))->get('/api/v1/attendance/summary', [ResourceController::class, 'attendanceSummary']);
        Route::middleware($v1('me:read'))->get('/api/v1/me', [ResourceController::class, 'me']);
    });

    // Student sign-in page: a normal browser form, so session and CSRF stay on.
    Route::get('/oauth/authorize', [OAuthController::class, 'show']);
    Route::post('/oauth/authorize', [OAuthController::class, 'submit']);
}

// Staff screens.
Route::middleware(['auth', CurrentSchoolAccount::class])->prefix('api/api-gateway')->group(function () {
    Route::middleware('role:admin')->group(function () {
        Route::get('/scopes', [AdminController::class, 'scopes']);
        Route::get('/clients', [AdminController::class, 'index']);
        Route::get('/usage', [AdminController::class, 'usage']);
        Route::post('/clients', [AdminController::class, 'store'])->middleware('throttle:20,1,post-api-gateway-clients');
        Route::put('/clients/{id}', [AdminController::class, 'update'])->whereNumber('id')->middleware('throttle:60,1,put-api-gateway-clients');
        Route::post('/clients/{id}/rotate', [AdminController::class, 'rotate'])->whereNumber('id')->middleware('throttle:20,1,post-api-gateway-rotate');
        Route::post('/clients/{id}/revoke', [AdminController::class, 'revoke'])->whereNumber('id')->middleware('throttle:20,1,post-api-gateway-revoke');
    });

    Route::middleware('role:admin,principal,teacher')->group(function () {
        Route::get('/pins/classrooms', [PinController::class, 'classrooms']);
        Route::get('/pins/classrooms/{classroomId}', [PinController::class, 'roster'])->whereNumber('classroomId');
        Route::post('/pins/classrooms/{classroomId}/issue', [PinController::class, 'issueClass'])->whereNumber('classroomId')->middleware('throttle:30,1,post-api-gateway-pins');
        Route::post('/pins/students/{studentId}/reset', [PinController::class, 'resetStudent'])->whereNumber('studentId')->middleware('throttle:60,1,post-api-gateway-pin-reset');
    });
});
