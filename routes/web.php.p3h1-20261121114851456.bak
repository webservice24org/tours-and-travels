<?php

use App\Http\Controllers\PublicSite\HomeController;
use Illuminate\Support\Facades\Route;

// Route::inertia('/', 'welcome')->name('home');
Route::get('/', HomeController::class)->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__ . '/settings.php';
require __DIR__ . '/bablys_public_booking_requests.php';
require __DIR__ . '/bablys_admin_booking_requests.php';
require __DIR__ . '/bablys_booking_quote_approvals.php';

require __DIR__ . '/bablys_public_tours.php';
