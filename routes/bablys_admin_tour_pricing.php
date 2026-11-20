<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Travel\TourPricingController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// Staff-only endpoints. The manager also rechecks gates inside its transaction.
Route::prefix('admin/internal/tours/{tour}/pricing')
    ->name('admin.internal.tours.pricing.')
    ->middleware(['auth', EnsureCmsStaff::class, 'throttle:60,1'])
    ->group(static function (): void {
        Route::get('/', [TourPricingController::class, 'show'])
            ->middleware(EnsureCmsPermission::class.':tours.view')->name('show');
        Route::put('/', [TourPricingController::class, 'replace'])
            ->middleware(EnsureCmsPermission::class.':tours.edit')->name('replace');
        Route::post('/preview', [TourPricingController::class, 'preview'])
            ->middleware(EnsureCmsPermission::class.':tours.view')->name('preview');
    });
