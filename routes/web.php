<?php

use App\Http\Middleware\SchoolBoundary;
use App\Support\Saan\Edition;
use Illuminate\Support\Facades\Route;

// S1 edition split (config('saan.edition')): the public surface (routes/public.php) and the internal surfaces (routes/internal.php)
// are registered independently, so the public edition has no login, CMS, work or API route at all. Combined registers both (same URLs as before).
Route::middleware(SchoolBoundary::class)->group(function() {
    if (Edition::serves('site')) require base_path('routes/public.php');
    if (Edition::serves('staff')) require base_path('routes/internal.php');
    // The fallback also carries the edition marker name that EditionGate checks when routes are cached.
    Route::fallback(fn()=>abort(404))->middleware(\App\Http\Middleware\PublicRedirect::class)->name('saan.edition.'.Edition::current());
});
