<?php

// Public surface ('site'): guest-visible notice lists. Staff API stays in routes/web.php (internal).
use Illuminate\Support\Facades\Route;
use Saan\Modules\WebNotices\Http\NoIndex;
use Saan\Modules\WebNotices\Http\PublicNoticeController;

Route::middleware(NoIndex::class)->group(function () {
    Route::get('/portal/notices/{slug}', [PublicNoticeController::class, 'show'])->middleware('throttle:120,1,get-portal-notices-show');
    Route::post('/portal/notices/{slug}/unlock', [PublicNoticeController::class, 'unlock'])->middleware('throttle:60,1,post-notices-unlock');
    // F9 anti-bot delivery (full-name and search-only lists). Limits per /64, /48 and list are counted inside NoticeGate on every call.
    Route::post('/portal/notices/{slug}/challenge', [PublicNoticeController::class, 'challenge']);
    Route::post('/portal/notices/{slug}/rows', [PublicNoticeController::class, 'rows']);
    Route::post('/portal/notices/{slug}/search', [PublicNoticeController::class, 'search']);
});