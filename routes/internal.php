<?php

// INTERNAL SURFACES ('staff'; guardian and api surfaces live in their modules): login, account, CMS, /work, staff APIs.
// Loaded by routes/web.php when the edition serves 'staff' (internal and combined). Never loaded by the public edition.

use App\Http\Controllers\SchoolController as School;
use App\Http\Controllers\CmsController as Cms;
use App\Http\Middleware\LocalPrototype;
use App\Http\Middleware\SchoolBoundary;
use App\Http\Middleware\CurrentSchoolAccount;
use App\Http\Middleware\OperationalAccount;
use App\Http\Controllers\AccountController as Account;
use App\Http\Controllers\UserManagementController as Users;
use App\Http\Controllers\WebsiteAdminController as WebsiteAdmin;
use App\Http\Controllers\WebsiteMediaController as WebsiteMedia;
use App\Http\Controllers\SchoolWebsiteController as Website;
use Illuminate\Support\Facades\Route;

// The internal edition has no public site: its front door sends people to the sign-in or to their workspace.
if (!\App\Support\Saan\Edition::serves('site')) {
    Route::get('/',fn()=>redirect(auth()->check()?'/work':'/login'));
    // Editors still preview uploaded media in the CMS; guests get nothing here (published media is served by the public edition).
    Route::get('/media/{id}',[WebsiteMedia::class,'show'])->whereNumber('id')->middleware('auth');
}
Route::get('/login',[Account::class,'loginPage'])->name('login');
Route::post('/login',[Account::class,'login'])->middleware('throttle:60,1,post-login');
Route::get('/login/mfa',[\App\Http\Controllers\AccountSecurityController::class,'challengePage']);
Route::post('/login/mfa',[\App\Http\Controllers\AccountSecurityController::class,'challenge'])->middleware('throttle:20,1,post-login-mfa');
Route::get('/forgot-password',[\App\Http\Controllers\PasswordLinkController::class,'forgotPage']);
Route::post('/forgot-password',[\App\Http\Controllers\PasswordLinkController::class,'forgot'])->middleware('throttle:10,1,post-forgot-password');
Route::get('/reset-password/{token}',[\App\Http\Controllers\PasswordLinkController::class,'resetPage'])->where('token','[A-Za-z0-9]{20,200}');
Route::post('/reset-password',[\App\Http\Controllers\PasswordLinkController::class,'reset'])->middleware('throttle:10,1,post-reset-password');
// S6: a public install redeems the one-time pairing code here (the code is the credential: no session, no CSRF). 404 outside the internal edition.
Route::post('/saan-pair/redeem',[\App\Http\Controllers\SaanPairController::class,'redeem'])->middleware('throttle:30,1,post-saan-pair-redeem')->withoutMiddleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,\Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,\Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class]);
Route::post('/demo-login',[School::class,'login'])->middleware([LocalPrototype::class,'throttle:30,1,post-demo-login']);
// N5: one generous per-user ceiling for every signed-in request; sensitive routes keep their own tighter limits.
Route::middleware(['auth',CurrentSchoolAccount::class,'throttle:600,1,signed-in'])->group(function() {
    Route::get('/development-plan',\App\Http\Controllers\DevelopmentPlanController::class)->name('development-plan');
    Route::get('/website-coverage',\App\Http\Controllers\WebsiteCoverageController::class);
    Route::get('/demo-library',[\App\Http\Controllers\DemoLibraryController::class,'index'])->middleware('module:demo-library');
    Route::get('/demo-library/files/{key}',[\App\Http\Controllers\DemoLibraryController::class,'file'])->where('key','[A-Za-z0-9_-]+')->middleware('module:demo-library');
    Route::get('/development-plan/export',[\App\Http\Controllers\DevelopmentPlanController::class,'export']);
    Route::get('/account',[Account::class,'show']);
    Route::post('/account/sessions/revoke',[\App\Http\Controllers\AccountSecurityController::class,'revokeOthers'])->middleware('throttle:10,1,post-account-sessions-revoke');
    Route::post('/account/mfa/start',[\App\Http\Controllers\AccountSecurityController::class,'mfaStart'])->middleware('throttle:10,1,post-account-mfa-start');
    Route::post('/account/mfa/confirm',[\App\Http\Controllers\AccountSecurityController::class,'mfaConfirm'])->middleware('throttle:10,1,post-account-mfa-confirm');
    Route::post('/account/mfa/disable',[\App\Http\Controllers\AccountSecurityController::class,'mfaDisable'])->middleware('throttle:10,1,post-account-mfa-disable');
    Route::put('/account/password',[Account::class,'password'])->middleware('throttle:10,1,put-account-password');
    Route::get('/admin/users',[Users::class,'index']);
    Route::post('/admin/users',[Users::class,'save'])->middleware('throttle:30,1,post-admin-users');
    Route::post('/admin/users/{id}/password-link',[\App\Http\Controllers\PasswordLinkController::class,'send'])->whereNumber('id')->middleware('throttle:10,1,post-admin-users-password-link');
    Route::put('/admin/users/{id}',[Users::class,'save'])->whereNumber('id')->middleware('throttle:30,1,put-admin-users-id');
    Route::get('/operations',[School::class,'operations']);
    Route::get('/work',\App\Support\Saan\WorkController::class);
    Route::get('/api/module-settings',[\App\Http\Controllers\ModuleSettingsController::class,'index'])->middleware('role:admin');
    Route::put('/api/module-settings',[\App\Http\Controllers\ModuleSettingsController::class,'update'])->middleware(['role:admin','throttle:60,1,put-api-module-settings']);
    Route::get('/cms',[Cms::class,'index']);
    Route::get('/theme-preview',[Website::class,'preview']);
    Route::get('/content-preview/{id}',[Website::class,'preview'])->whereNumber('id');
    Route::get('/hero-preview/{id}',[Website::class,'heroPreview'])->whereNumber('id');
    Route::get('/observance-preview/{key}',[\App\Http\Controllers\ObservancePreviewController::class,'show'])->name('observance.preview')->middleware('signed');
    Route::post('/api/site/observance/preview',[\App\Http\Controllers\ObservancePreviewController::class,'create'])->middleware('throttle:30,1,post-api-site-observance-preview');
    Route::get('/api/site/presentation',[\App\Http\Controllers\PresentationController::class,'read']);
    Route::put('/api/site/presentation',[\App\Http\Controllers\PresentationController::class,'save'])->middleware('throttle:60,1,put-api-site-presentation');
    Route::post('/api/site/presentation/preview',[\App\Http\Controllers\PresentationController::class,'preview'])->middleware('throttle:120,1,post-api-site-presentation-preview');
    Route::get('/api/site/slides',[\App\Http\Controllers\HeroController::class,'index']);
    Route::put('/api/site/slides/order',[\App\Http\Controllers\HeroController::class,'order']);
    Route::get('/api/site/redirects',[\App\Http\Controllers\WebsiteToolsController::class,'redirects']);
    Route::post('/api/site/redirects',[\App\Http\Controllers\WebsiteToolsController::class,'saveRedirect']);
    Route::put('/api/site/redirects/{id}',[\App\Http\Controllers\WebsiteToolsController::class,'saveRedirect'])->whereNumber('id');
    Route::get('/api/site/links',[\App\Http\Controllers\WebsiteToolsController::class,'links']);
    Route::get('/api/site/updates',[\App\Http\Controllers\UpdateController::class,'index']);
    Route::post('/api/site/updates/backups/purge',[\App\Http\Controllers\UpdateController::class,'purgeBackups'])->middleware('throttle:6,1,post-api-site-updates-purge');
    Route::get('/whats-new',[\App\Http\Controllers\ModuleManagerController::class,'whatsNew']);
    Route::middleware('role:admin')->group(function() {
        Route::get('/admin/modules',[\App\Http\Controllers\ModuleManagerController::class,'page']);
        Route::get('/admin/publish',[\App\Http\Controllers\PublishAdminController::class,'page']);
        Route::get('/api/site/publish',[\App\Http\Controllers\PublishAdminController::class,'status']);
        Route::get('/admin/pairing',[\App\Http\Controllers\PairingAdminController::class,'page']);
        Route::post('/admin/pairing/code',[\App\Http\Controllers\PairingAdminController::class,'issue'])->middleware('throttle:10,1,post-admin-pairing-code');
        Route::post('/admin/publish/full',[\App\Http\Controllers\PublishAdminController::class,'full'])->middleware('throttle:6,1,post-admin-publish-full');
        Route::get('/api/site/modules',[\App\Http\Controllers\ModuleManagerController::class,'index']);
        Route::post('/api/site/modules/{id}/state',[\App\Http\Controllers\ModuleManagerController::class,'state'])->where('id','[a-z][a-z0-9-]{1,39}')->middleware('throttle:20,1,post-api-site-modules-state');
        Route::post('/api/site/modules/{id}/export',[\App\Http\Controllers\ModuleManagerController::class,'export'])->where('id','[a-z][a-z0-9-]{1,39}')->middleware('throttle:3,1,post-api-site-modules-export');
        Route::get('/api/site/updates/feed',[\App\Http\Controllers\UpdateController::class,'feed']);
        Route::put('/api/site/updates/feed',[\App\Http\Controllers\UpdateController::class,'feedSwitch'])->middleware('throttle:10,1,put-api-site-updates-feed');
        Route::post('/api/site/updates/feed/check',[\App\Http\Controllers\UpdateController::class,'feedCheck'])->middleware('throttle:6,1,post-api-site-updates-feed-check');
        // self-update page: status, check, one package per download call, mode (installing reuses POST /api/site/updates/apply below)
        Route::get('/admin/updates',[\App\Http\Controllers\SelfUpdateController::class,'page']);
        Route::get('/api/site/updates/self',[\App\Http\Controllers\SelfUpdateController::class,'status']);
        Route::post('/api/site/updates/self/check',[\App\Http\Controllers\SelfUpdateController::class,'check'])->middleware('throttle:6,1,post-api-site-updates-self-check');
        Route::post('/api/site/updates/self/download',[\App\Http\Controllers\SelfUpdateController::class,'download'])->middleware('throttle:90,1,post-api-site-updates-self-download');
        Route::put('/api/site/updates/self/mode',[\App\Http\Controllers\SelfUpdateController::class,'mode'])->middleware('throttle:10,1,put-api-site-updates-self-mode');
    });
    Route::post('/api/site/updates/apply',[\App\Http\Controllers\UpdateController::class,'apply'])->middleware('throttle:3,1,post-api-site-updates-apply');
    Route::get('/api/site/deployment',[\App\Http\Controllers\WebsiteToolsController::class,'deployment']);
    Route::get('/api/site/state',[WebsiteAdmin::class,'state']);
    Route::get('/api/site/mail',[\App\Http\Controllers\MailSettingsController::class,'read']);
    Route::put('/api/site/mail',[\App\Http\Controllers\MailSettingsController::class,'save'])->middleware('throttle:20,1,put-api-site-mail');
    Route::post('/api/site/mail/reset',[\App\Http\Controllers\MailSettingsController::class,'reset'])->middleware('throttle:5,1,post-api-site-mail-reset');
    Route::post('/api/site/mail/test',[\App\Http\Controllers\MailSettingsController::class,'test'])->middleware('throttle:3,1,post-api-site-mail-test');
    Route::get('/api/site/content',[WebsiteAdmin::class,'listing']);
    Route::get('/api/site/content/{id}',[WebsiteAdmin::class,'read'])->whereNumber('id');
    Route::get('/api/site/content/{id}/revisions/{revision}',[WebsiteAdmin::class,'revision'])->whereNumber(['id','revision']);
    Route::post('/api/site/content',[WebsiteAdmin::class,'save']);
    Route::put('/api/site/content/{id}',[WebsiteAdmin::class,'save'])->whereNumber('id');
    Route::post('/api/site/content/{id}/action',[WebsiteAdmin::class,'action'])->whereNumber('id');
    Route::put('/api/site/settings',[WebsiteAdmin::class,'settings']);
    Route::post('/api/site/categories',[WebsiteAdmin::class,'category']);
    Route::put('/api/site/categories/{id}',[WebsiteAdmin::class,'category'])->whereNumber('id');
    Route::delete('/api/site/categories/{id}',[WebsiteAdmin::class,'removeCategory'])->whereNumber('id');
    Route::get('/api/site/media',[WebsiteMedia::class,'index']);
    Route::post('/api/site/media',[WebsiteMedia::class,'upload'])->middleware('throttle:60,1,post-api-site-media');
    Route::put('/api/site/media/{id}',[WebsiteMedia::class,'update'])->whereNumber('id');
    Route::post('/api/site/media/{id}/variant',[WebsiteMedia::class,'variant'])->whereNumber('id')->middleware('throttle:20,1,post-api-site-media-id-variant');
    Route::delete('/api/site/media/{id}',[WebsiteMedia::class,'remove'])->whereNumber('id');
    Route::get('/api/site/messages',[WebsiteAdmin::class,'messages']);
    Route::put('/api/site/messages/{id}',[WebsiteAdmin::class,'message'])->whereNumber('id');
    Route::get('/api/site/audit',[WebsiteAdmin::class,'audit']);
    Route::post('/api/site/publish-due',[WebsiteAdmin::class,'publishDue']);
    Route::get('/api/cms/state',[Cms::class,'state']);
    Route::put('/api/cms/theme',[Cms::class,'theme']);
    Route::post('/api/cms/media',[Cms::class,'upload'])->middleware('throttle:60,1,post-api-cms-media');
    Route::get('/api/cms/posts/{id}',[Cms::class,'getPost'])->whereNumber('id');
    Route::post('/api/cms/posts',[Cms::class,'savePost']);
    Route::put('/api/cms/posts/{id}',[Cms::class,'savePost'])->whereNumber('id');
    Route::post('/logout',[Account::class,'logout']);
    Route::middleware([OperationalAccount::class,'module:operations'])->group(function() {
    Route::get('/api/state',[School::class,'state']);
    Route::get('/api/operations/catalogue',[School::class,'catalogue']);
    // N8 cutover: the prototype write paths are retired. They answer 410 so old bookmarks and clients are told where the work moved.
    Route::post('/api/lessons',[School::class,'moved'])->defaults('page','timetable');
    Route::put('/api/lessons/{id}',[School::class,'moved'])->whereNumber('id')->defaults('page','timetable');
    Route::delete('/api/lessons/{id}',[School::class,'moved'])->whereNumber('id')->defaults('page','timetable');
    Route::post('/api/duties',[School::class,'moved'])->defaults('page','duty');
    Route::put('/api/duties/{id}',[School::class,'moved'])->whereNumber('id')->defaults('page','duty');
    Route::get('/api/duty-suggestions',[School::class,'suggestions']);
    Route::post('/api/leaves',[School::class,'moved'])->defaults('page','leave');
    Route::post('/api/leaves/{id}/decision',[School::class,'moved'])->whereNumber('id')->defaults('page','leave-approve');
    Route::post('/api/attendance',[School::class,'moved'])->defaults('page','attendance');
    Route::post('/api/grades',[School::class,'moved'])->defaults('page','scores');
    Route::post('/api/posts',[School::class,'post'])->middleware('throttle:30,1,post-api-posts');
    Route::post('/api/posts/{id}/publish',[School::class,'publish'])->whereNumber('id')->middleware('throttle:30,1,post-api-posts-id-publish');
    });
});
