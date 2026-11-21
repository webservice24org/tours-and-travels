<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Cms\BablysNavigationMenusController;
use App\Http\Controllers\Public\BablysPublicNavigationMenusController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// Published-only API; returns an empty map until P4A.3 publishes menu snapshots.
Route::get('/api/public/navigation-menus', BablysPublicNavigationMenusController::class)
    ->name('bablys.public.navigation-menus');

Route::middleware(['auth', EnsureCmsStaff::class, EnsureCmsPermission::class.':settings.manage'])
    ->prefix('admin/navigation-menus')
    ->name('admin.navigation-menus.')
    ->group(static function (): void {
        Route::get('/', [BablysNavigationMenusController::class, 'index'])->name('index');
        Route::post('/', [BablysNavigationMenusController::class, 'store'])->middleware('throttle:20,1')->name('store');
        Route::get('/{menu}', [BablysNavigationMenusController::class, 'show'])->whereNumber('menu')->name('show');
        Route::put('/{menu}', [BablysNavigationMenusController::class, 'update'])->whereNumber('menu')->middleware('throttle:30,1')->name('update');
        Route::delete('/{menu}', [BablysNavigationMenusController::class, 'destroy'])->whereNumber('menu')->middleware('throttle:10,1')->name('destroy');
    });
