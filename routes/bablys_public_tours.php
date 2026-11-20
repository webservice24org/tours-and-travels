<?php

declare(strict_types=1);

use App\Http\Controllers\Public\Travel\PublicTourIndexController;
use App\Http\Controllers\Public\Travel\PublicTourShowController;
use Illuminate\Support\Facades\Route;

// Include ONCE from routes/web.php. This endpoint is public and read-only.
// P3F.3A adds a read-only published-tour detail endpoint.
Route::get('/tours', PublicTourIndexController::class)
    ->middleware('throttle:90,1')
    ->name('public.tours.index');


// Keep the precise path; the existing /tours/{tour:slug}/booking-request
// has three path segments and remains a separate nonbinding request route.
Route::get('/tours/{slug}', PublicTourShowController::class)
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->middleware('throttle:90,1')
    ->name('public.tours.show');
