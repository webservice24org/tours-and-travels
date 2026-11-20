<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Travel\AdminBookingRequestController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// Restrict traveler contact PII to explicitly authorized senior travel staff.
// Dedicated bookings.view permission is deferred until RBAC seed/policy expansion.
Route::get('/admin/booking-requests', AdminBookingRequestController::class)
    ->middleware(['auth', EnsureCmsStaff::class,
        EnsureCmsPermission::class.':tours.edit', EnsureCmsPermission::class.':tours.publish'])
    ->name('admin.booking-requests.index');
