<?php

declare(strict_types=1);

use App\Http\Controllers\Public\BablysPublicChromeController;
use Illuminate\Support\Facades\Route;

// P4A.4: this public endpoint returns published CMS settings and navigation only.
// It never exposes draft or preview content, even to a logged-in editor.
Route::get('/api/public/site-chrome', BablysPublicChromeController::class)
    ->name('bablys.public.site-chrome');
