<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Support\Facades\Route;
use Saan\Modules\Documents\Http\DocumentsController as D;

// Every signed-in staff role may reach the API; clerk rights (admin, principal, accounts in documents.clerk_user_ids) are checked in Documents.
Route::middleware(['auth', CurrentSchoolAccount::class, 'role:admin,principal,teacher,editor'])->group(function () {
    Route::get('/api/documents/meta', [D::class, 'meta']);
    Route::get('/api/documents/inbox', [D::class, 'inbox']);
    Route::post('/api/documents/inbox/{routeId}/ack', [D::class, 'acknowledge'])->whereNumber('routeId')->middleware('throttle:60,1,post-api-documents-ack');
    Route::get('/api/documents/files/{fileId}/download', [D::class, 'download'])->whereNumber('fileId')->middleware('throttle:120,1,get-api-documents-download');
    Route::get('/api/documents/books', [D::class, 'books']);
    Route::put('/api/documents/books/{id}', [D::class, 'updateBook'])->whereNumber('id')->middleware('throttle:30,1,put-api-documents-books');
    Route::get('/api/documents/recipients', [D::class, 'recipients']);
    Route::get('/api/documents/clerks', [D::class, 'clerks']);
    Route::put('/api/documents/clerks', [D::class, 'setClerks'])->middleware('throttle:20,1,put-api-documents-clerks');
    Route::get('/documents/print', [D::class, 'print'])->middleware('throttle:60,1,get-documents-print');

    Route::get('/api/documents', [D::class, 'index']);
    Route::post('/api/documents', [D::class, 'store'])->middleware('throttle:60,1,post-api-documents');
    Route::get('/api/documents/{id}', [D::class, 'show'])->whereNumber('id');
    Route::put('/api/documents/{id}', [D::class, 'update'])->whereNumber('id')->middleware('throttle:60,1,put-api-documents');
    Route::post('/api/documents/{id}/cancel', [D::class, 'cancel'])->whereNumber('id')->middleware('throttle:30,1,post-api-documents-cancel');
    Route::post('/api/documents/{id}/done', [D::class, 'done'])->whereNumber('id')->middleware('throttle:30,1,post-api-documents-done');
    Route::post('/api/documents/{id}/routes', [D::class, 'route'])->whereNumber('id')->middleware('throttle:60,1,post-api-documents-routes');
    Route::delete('/api/documents/{id}/routes/{routeId}', [D::class, 'unroute'])->whereNumber(['id', 'routeId'])->middleware('throttle:60,1,delete-api-documents-routes');
    Route::post('/api/documents/{id}/files', [D::class, 'upload'])->whereNumber('id')->middleware('throttle:30,1,post-api-documents-files');
    Route::delete('/api/documents/files/{fileId}', [D::class, 'deleteFile'])->whereNumber('fileId')->middleware('throttle:30,1,delete-api-documents-files');
});
