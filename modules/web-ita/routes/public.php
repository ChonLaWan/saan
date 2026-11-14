<?php

// Public surface ('site'): guest-visible ITA pages. Staff API stays in routes/web.php (internal).
use Illuminate\Support\Facades\Route;
use Saan\Modules\WebIta\Http\ItaController;

Route::get('/portal/ita', [ItaController::class, 'public']);
Route::get('/portal/ita/{fiscalYear}', [ItaController::class, 'public'])->where('fiscalYear', '[0-9]{1,9}'); // a longer number would overflow the integer cast: it is a 404, not a 500