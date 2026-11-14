<?php

use App\Http\Middleware\CurrentSchoolAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Saan\Modules\WebSocial\SocialFeed;

Route::middleware(['auth', CurrentSchoolAccount::class])->group(function () {
    Route::get('/api/social', fn (SocialFeed $feed) => response()->json($feed->read()));
    Route::put('/api/social', function (Request $r, SocialFeed $feed) {
        $d = $r->validate(['version' => 'required|integer|min:0', 'facebook_url' => 'present|nullable|string|max:300', 'heading' => 'required|string|max:120', 'height' => 'required|integer|between:300,1200', 'enabled' => 'required|boolean']);
        $d['facebook_url'] = trim((string) ($d['facebook_url'] ?? ''));
        return response()->json($feed->save($d, (int) $d['version']));
    })->middleware('throttle:30,1,put-api-social');
});
