<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Travel\AdminBookingsPageController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// P3H.1 staff-only read endpoints. No confirmations, cancellations, or payment actions.
Route::middleware([
    'auth', EnsureCmsStaff::class,
    EnsureCmsPermission::class.':tours.edit',
    EnsureCmsPermission::class.':tours.publish',
])->group(static function (): void {
    Route::get('/admin/bookings', [AdminBookingsPageController::class, 'index'])
        ->name('admin.bookings.index');
    Route::get('/admin/bookings/{reference}', [AdminBookingsPageController::class, 'show'])
        ->where('reference', '[A-Za-z0-9][A-Za-z0-9._:-]{7,99}')
        ->name('admin.bookings.show');
});
