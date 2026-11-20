<?php

declare(strict_types=1);

use App\Http\Controllers\Public\Travel\BookingRequestQuoteApprovalController as Quotes;
use Illuminate\Support\Facades\Route;

// Loaded once from routes/web.php; inherits web CSRF/session middleware.
Route::post('/booking-requests/guest/quote-review', [Quotes::class, 'guestPreview'])
    ->middleware('throttle:12,1')->name('booking-requests.guest.quote-review');
Route::post('/booking-requests/guest/quote-accept', [Quotes::class, 'guestAccept'])
    ->middleware('throttle:6,1')->name('booking-requests.guest.quote-accept');

Route::middleware(['auth', 'verified'])->group(static function (): void {
    Route::post('/account/booking-requests/{reference}/quote-review', [Quotes::class, 'customerPreview'])
        ->middleware('throttle:15,1')->name('booking-requests.customer.quote-review');
    Route::post('/account/booking-requests/{reference}/quote-accept', [Quotes::class, 'customerAccept'])
        ->middleware('throttle:8,1')->name('booking-requests.customer.quote-accept');
});

// NO staff conversion HTTP route until P3E.5B.3D MySQL race/authorization QA.
