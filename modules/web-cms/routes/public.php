<?php

// web-cms F4, public surface ('site'): group hub pages and the counting download route. Read only; the only write is the aggregate download counter.
use Illuminate\Support\Facades\Route;
use Saan\Modules\WebCms\Http\HubPublicController;

// Only /portal/* is open on the public edition (Edition::PUBLIC_ALLOWED), so the download route lives under it as well.
Route::get('/portal/groups', [HubPublicController::class, 'index'])->middleware('throttle:120,1,get-portal-groups');
Route::get('/portal/groups/{slug}', [HubPublicController::class, 'show'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')->middleware('throttle:120,1,get-portal-groups-slug');
Route::get('/portal/groups/{slug}/docs/{cat}', [HubPublicController::class, 'docs'])->where(['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'cat' => '[0-9]{1,10}'])->middleware('throttle:120,1,get-portal-groups-docs');
Route::get('/portal/groups/{slug}/news', [HubPublicController::class, 'news'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')->middleware('throttle:120,1,get-portal-groups-news');
Route::get('/portal/d/{id}', [HubPublicController::class, 'download'])->where('id', '[0-9]{1,10}')->middleware('throttle:60,1,get-portal-d');
