<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Travel\AdminBookingActionsController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// P3H.2: POST-only staff mutations. This file must be required exactly once
// from routes/web.php; web middleware supplies CSRF protection.
Route::middleware([
    'auth', EnsureCmsStaff::class,
    EnsureCmsPermission::class.':tours.edit',
    EnsureCmsPermission::class.':tours.publish',
])->group(static function (): void {
    Route::post('/admin/bookings/{reference}/confirm', [AdminBookingActionsController::class, 'confirm'])
        ->where('reference', '[A-Za-z0-9][A-Za-z0-9._:-]{7,99}')
        ->name('admin.bookings.confirm');
    Route::post('/admin/bookings/{reference}/cancel', [AdminBookingActionsController::class, 'cancel'])
        ->where('reference', '[A-Za-z0-9][A-Za-z0-9._:-]{7,99}')
        ->name('admin.bookings.cancel');
    Route::post('/admin/booking-requests/{reference}/convert', [AdminBookingActionsController::class, 'convert'])
        ->where('reference', 'BRQ-[a-f0-9]{32}')
        ->name('admin.booking-requests.convert');
});
