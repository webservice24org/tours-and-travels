<?php

declare(strict_types=1);

use App\Http\Controllers\Account\Travel\CustomerTravelAccountController as CustomerTravel;
use Illuminate\Support\Facades\Route;

// Registered once from routes/web.php. Web session + CSRF are inherited.
// Authentication is the existing Laravel/Fortify login & verified-email flow.
Route::middleware(['auth', 'verified', 'throttle:60,1'])->group(static function (): void {
    Route::get('/account/travel', [CustomerTravel::class, 'index'])->name('customer.travel.index');
    Route::get('/account/bookings', [CustomerTravel::class, 'bookings'])->name('customer.bookings.index');
    Route::get('/account/bookings/{reference}', [CustomerTravel::class, 'show'])->name('customer.bookings.show');
});
