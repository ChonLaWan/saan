<?php

// Public surface ('site'): the complaint form, the receipt and the status page. No login. The public edition stores nothing about a complaint:
// the form is sealed for the internal edition (Inbox kind "complaint") and the status page reads the projected pub_complaint_status only.
// The tracking code is only ever sent by POST (a code in a URL would end up in logs and history). Every attempt is also counted per /64 and /48 in the controller.
use Illuminate\Support\Facades\Route;
use Saan\Modules\Complaints\Http\PublicController as P;

Route::get('/portal/complaints', [P::class, 'form'])->middleware('throttle:60,1,get-portal-complaints');
Route::post('/portal/complaints', [P::class, 'submit'])->middleware('throttle:20,1,post-portal-complaints');
Route::get('/portal/complaints/status', [P::class, 'statusForm'])->middleware('throttle:60,1,get-portal-complaint-status');
Route::post('/portal/complaints/status', [P::class, 'status'])->middleware('throttle:30,1,post-portal-complaint-status');
// the .csv form must be declared before {year}
Route::get('/portal/complaints/reports/{year}.csv', [P::class, 'reportCsv'])->whereNumber('year')->middleware('throttle:30,1,get-portal-complaint-report-csv');
Route::get('/portal/complaints/reports/{year}', [P::class, 'report'])->whereNumber('year')->middleware('throttle:60,1,get-portal-complaint-report');
