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

// P3H.1 senior-staff read-only bookings
require __DIR__.'/bablys_admin_bookings.php';

// P3H.2 authorized booking operations
require __DIR__.'/bablys_admin_booking_actions.php';

// P3H.4 authorized booking operations
require __DIR__.'/bablys_customer_account.php';

// P4A.1 BABLYS GLOBAL SITE SETTINGS
require __DIR__.'/bablys_site_settings.php';

// P4A.2 BABLYS NAVIGATION MENU DRAFTS
require __DIR__.'/bablys_navigation_menus.php';

// P4A.3 BABLYS CMS PUBLISHING
require __DIR__.'/bablys_cms_publishing.php';

// P4A.4 BABLYS PUBLIC CMS CHROME
require __DIR__.'/bablys_public_chrome.php';
require __DIR__.'/bablys_global_settings_workspace.php';
