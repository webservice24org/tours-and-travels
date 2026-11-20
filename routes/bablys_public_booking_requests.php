<?php

declare(strict_types=1);

use App\Http\Controllers\Public\Travel\PublicBookingRequestController as BookingRequests;
use Illuminate\Support\Facades\Route;

// This registry must be required once by routes/web.php to retain web session + CSRF.
Route::get('/tours/{tour:slug}/booking-request', [BookingRequests::class, 'create'])
    ->middleware('throttle:60,1')->name('booking-requests.create');
Route::post('/booking-requests/quote', [BookingRequests::class, 'quote'])
    ->middleware('throttle:20,1')->name('booking-requests.quote');
Route::post('/booking-requests', [BookingRequests::class, 'store'])
    ->middleware('throttle:5,1')->name('booking-requests.store');
Route::get('/booking-requests/submitted', [BookingRequests::class, 'submittedPage'])
    ->name('booking-requests.submitted');
Route::get('/booking-requests/verify', [BookingRequests::class, 'verifyPage'])
    ->name('booking-requests.verify.page');
Route::post('/booking-requests/verify', [BookingRequests::class, 'verifyGuest'])
    ->middleware('throttle:10,1')->name('booking-requests.verify');
Route::get('/booking-requests/manage', [BookingRequests::class, 'managePage'])
    ->name('booking-requests.manage.page');
Route::post('/booking-requests/guest/status', [BookingRequests::class, 'guestStatus'])
    ->middleware('throttle:12,1')->name('booking-requests.guest.status');
Route::post('/booking-requests/guest/withdraw', [BookingRequests::class, 'guestWithdraw'])
    ->middleware('throttle:6,1')->name('booking-requests.guest.withdraw');
Route::middleware(['auth', 'verified'])->group(static function (): void {
    Route::get('/account/booking-requests', [BookingRequests::class, 'customerIndex'])
        ->name('booking-requests.customer.index');
    Route::post('/account/booking-requests/{reference}/withdraw', [BookingRequests::class, 'customerWithdraw'])
        ->middleware('throttle:10,1')->name('booking-requests.customer.withdraw');
});
