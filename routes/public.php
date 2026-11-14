<?php

// PUBLIC SURFACE ('site'): everything a guest can open on the school website. Loaded by routes/web.php when the edition serves 'site'
// (public and combined). No login, no staff route and no API lives here; internal screens are in routes/internal.php.

use App\Http\Controllers\WebsiteMediaController as WebsiteMedia;
use App\Http\Controllers\SchoolWebsiteController as Website;
use Illuminate\Support\Facades\Route;

// Front door: the combined install keeps the old entry (signed-in staff go to their workspace); the public edition always shows the site.
Route::get('/',[Website::class,\App\Support\Saan\Edition::isPublic() ? 'home' : 'entry'])->middleware(\App\Http\Middleware\PublicRedirect::class);
Route::get('/portal',[Website::class,'home'])->middleware(\App\Http\Middleware\PublicRedirect::class);
// Page and file throttles are ceilings against scraping floods, counted per client address. A whole school often leaves through ONE address (NAT) and
// one page loads dozens of media files, so the numbers are high on purpose: a computer room opening the site together must never see 429.
Route::get('/portal/directory',[Website::class,'directory'])->middleware('throttle:1200,1,get-portal-directory');
Route::get('/portal/contact',[Website::class,'contact'])->middleware('throttle:600,1,get-portal-contact');
Route::post('/portal/contact',[Website::class,'receive'])->middleware('throttle:5,1,post-portal-contact');
Route::get('/portal/search',[Website::class,'search'])->middleware('throttle:300,1,get-portal-search');
Route::get('/sitemap.xml',[Website::class,'sitemap']);
// Only real content sections; other /portal/* paths belong to modules (for example /portal/ita).
$sections=implode('|',array_column(\App\Domain\Cms\ContentTypes::TYPES,'route'));
Route::get('/portal/{section}/{slug}',[Website::class,'article'])->where(['section'=>$sections,'slug'=>'[a-zA-Z0-9-]+'])->middleware([\App\Http\Middleware\PublicRedirect::class,'throttle:2400,1,get-portal-article']);
Route::get('/portal/{section}',[Website::class,'collection'])->where('section',$sections)->middleware([\App\Http\Middleware\PublicRedirect::class,'throttle:2400,1,get-portal-collection']);
Route::get('/media/{id}',[WebsiteMedia::class,'show'])->whereNumber('id')->middleware('throttle:12000,1,get-media-id');
// S4: signed push from the internal edition (the controller answers 404 outside the public edition). No session or CSRF: the signature is the credential.
Route::post('/_saan/push',[\App\Http\Controllers\SaanPushController::class,'receive'])->withoutMiddleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,\Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,\Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class]);
// S5: the internal edition pulls sealed envelopes (signed with the pull key, replay protected) and acknowledges them. Same rules as the push endpoint.
$bare=[\Illuminate\Cookie\Middleware\EncryptCookies::class,\Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,\Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class];
Route::get('/_saan/inbox',[\App\Http\Controllers\SaanInboxController::class,'handle'])->withoutMiddleware($bare); // no per-address throttle: SaanInboxController counts only requests that failed the signature
Route::post('/_saan/inbox/ack',[\App\Http\Controllers\SaanInboxController::class,'handle'])->withoutMiddleware($bare);
// S6: one-time pairing form for hosts without SSH; answers 404 unless the owner has put storage/app/private/saan-pair.token in place.
Route::get('/_saan/setup',[\App\Http\Controllers\SaanSetupController::class,'show'])->middleware('throttle:20,1,get-saan-setup');
Route::post('/_saan/setup',[\App\Http\Controllers\SaanSetupController::class,'pair'])->middleware('throttle:5,1,post-saan-setup');
