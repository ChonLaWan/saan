<?php

// web-cms F4, staff surface: hub screens for the /work shell (assets/modules/web-cms/hubs.js) and their APIs.
// Staff roles read; every write is checked again in the controller (admin, or the head of that hub's group); hub create/delete are admin only.
use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\WebCms\Http\HubAdminController as Hubs;

Route::middleware(['auth', CurrentSchoolAccount::class, 'role:admin,principal,editor,teacher'])->group(function () {
    Route::get('/hub-preview/{id}', [Hubs::class, 'preview'])->whereNumber('id');
    Route::prefix('api/cms/hubs')->group(function () {
        Route::get('/', [Hubs::class, 'index']);
        Route::post('/', [Hubs::class, 'store'])->middleware('throttle:30,1,post-api-cms-hubs');
        Route::post('/template', [Hubs::class, 'storeFromTemplate'])->middleware('throttle:30,1,post-api-cms-hubs-template');
        Route::get('/documents/{did}/file', [Hubs::class, 'file'])->whereNumber('did')->middleware('throttle:120,1,get-api-cms-hubs-file');
        Route::get('/{id}', [Hubs::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [Hubs::class, 'update'])->whereNumber('id')->middleware('throttle:60,1,put-api-cms-hubs');
        Route::delete('/{id}', [Hubs::class, 'destroy'])->whereNumber('id')->middleware('throttle:20,1,delete-api-cms-hubs');
        Route::post('/{id}/categories', [Hubs::class, 'addCategory'])->whereNumber('id')->middleware('throttle:60,1,post-api-cms-hubs-categories');
        Route::put('/{id}/categories/order', [Hubs::class, 'orderCategories'])->whereNumber('id')->middleware('throttle:60,1,put-api-cms-hubs-categories-order');
        Route::put('/{id}/categories/{cid}', [Hubs::class, 'updateCategory'])->whereNumber(['id', 'cid'])->middleware('throttle:60,1,put-api-cms-hubs-categories');
        Route::delete('/{id}/categories/{cid}', [Hubs::class, 'deleteCategory'])->whereNumber(['id', 'cid'])->middleware('throttle:30,1,delete-api-cms-hubs-categories');
        Route::post('/{id}/documents', [Hubs::class, 'addDocuments'])->whereNumber('id')->middleware('throttle:30,1,post-api-cms-hubs-documents');
        Route::post('/{id}/documents/publish', [Hubs::class, 'publishDocuments'])->whereNumber('id')->middleware('throttle:30,1,post-api-cms-hubs-documents-publish');
        Route::put('/{id}/documents/order', [Hubs::class, 'orderDocuments'])->whereNumber('id')->middleware('throttle:60,1,put-api-cms-hubs-documents-order');
        // multipart cannot be sent with PUT, so a document edit that may carry a replacement file is a POST
        Route::post('/{id}/documents/{did}', [Hubs::class, 'updateDocument'])->whereNumber(['id', 'did'])->middleware('throttle:60,1,post-api-cms-hubs-documents-id');
        Route::delete('/{id}/documents/{did}', [Hubs::class, 'deleteDocument'])->whereNumber(['id', 'did'])->middleware('throttle:60,1,delete-api-cms-hubs-documents');
    });
});
