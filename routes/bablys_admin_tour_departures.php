<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Travel\TourDepartureController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// Protected inventory endpoints. No public booking or seat mutation routes.
Route::prefix('admin/internal/tours/{tour}/departures')
    ->name('admin.internal.tours.departures.')
    ->middleware(['auth', EnsureCmsStaff::class, 'throttle:60,1'])
    ->group(static function (): void {
        Route::get('/', [TourDepartureController::class, 'show'])
            ->middleware(EnsureCmsPermission::class.':tours.view')->name('show');
        Route::put('/', [TourDepartureController::class, 'replace'])
            ->middleware(EnsureCmsPermission::class.':tours.edit')->name('replace');
    });
