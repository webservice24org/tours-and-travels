<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Travel\TourController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// Included once by the existing routes/bablys_admin.php registry.
Route::prefix('admin/internal/tours')
    ->name('admin.internal.tours.')
    ->middleware(['auth', EnsureCmsStaff::class, 'throttle:60,1'])
    ->group(static function (): void {
        Route::get('/', [TourController::class, 'index'])
            ->middleware(EnsureCmsPermission::class.':tours.view')->name('index');
        Route::get('/taxonomy-options', [TourController::class, 'taxonomyOptions'])
            ->middleware(EnsureCmsPermission::class.':tours.view')->name('taxonomy-options');
        Route::post('/', [TourController::class, 'store'])
            ->middleware(EnsureCmsPermission::class.':tours.create')->name('store');
        Route::get('/{tour}', [TourController::class, 'show'])
            ->middleware(EnsureCmsPermission::class.':tours.view')->name('show');
        Route::put('/{tour}', [TourController::class, 'update'])
            ->middleware(EnsureCmsPermission::class.':tours.edit')->name('update');
        Route::delete('/{tour}', [TourController::class, 'destroy'])
            ->middleware(EnsureCmsPermission::class.':tours.edit')->name('destroy');
    });
