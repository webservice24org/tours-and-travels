<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Travel\TourItineraryController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// P3E.2: no public itinerary endpoints; the P3E.1 manager rechecks permissions.
Route::prefix('admin/internal/tours/{tour}/itinerary')
    ->name('admin.internal.tours.itinerary.')
    ->middleware(['auth', EnsureCmsStaff::class, 'throttle:60,1'])
    ->group(static function (): void {
        Route::get('/', [TourItineraryController::class, 'show'])
            ->middleware(EnsureCmsPermission::class.':tours.view')->name('show');
        Route::put('/', [TourItineraryController::class, 'replace'])
            ->middleware(EnsureCmsPermission::class.':tours.edit')->name('replace');
    });
