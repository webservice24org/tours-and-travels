<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Travel\TourCategoryController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// Loaded exactly once by routes/bablys_admin.php, already registered in web.
Route::prefix('admin/internal/tour-categories')
    ->name('admin.internal.tour-categories.')
    ->middleware(['auth', EnsureCmsStaff::class, EnsureCmsPermission::class.':taxonomy.manage', 'throttle:60,1'])
    ->group(static function (): void {
        Route::get('/', [TourCategoryController::class, 'index'])->name('index');
        Route::get('/options', [TourCategoryController::class, 'options'])->name('options');
        Route::post('/', [TourCategoryController::class, 'store'])->name('store');
        Route::get('/{category}', [TourCategoryController::class, 'show'])->name('show');
        Route::put('/{category}', [TourCategoryController::class, 'update'])->name('update');
        Route::delete('/{category}', [TourCategoryController::class, 'destroy'])->name('destroy');
    });
