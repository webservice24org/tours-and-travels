<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Travel\DestinationController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// Loaded exactly once from the existing Bablys admin route registry.
Route::prefix('admin/internal/destinations')
    ->name('admin.internal.destinations.')
    ->middleware(['auth', EnsureCmsStaff::class, EnsureCmsPermission::class.':taxonomy.manage', 'throttle:60,1'])
    ->group(static function (): void {
        Route::get('/', [DestinationController::class, 'index'])->name('index');
        Route::get('/options', [DestinationController::class, 'options'])->name('options');
        Route::post('/', [DestinationController::class, 'store'])->name('store');
        Route::get('/{destination}', [DestinationController::class, 'show'])->name('show');
        Route::put('/{destination}', [DestinationController::class, 'update'])->name('update');
        Route::delete('/{destination}', [DestinationController::class, 'destroy'])->name('destroy');
    });
