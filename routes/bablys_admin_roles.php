<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Roles\CmsCustomRoleController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// Loaded by routes/bablys_admin.php, already inside Bablys' web middleware group.
// The domain service independently requires real super_admin membership.
Route::prefix('admin/internal/cms-roles')
    ->name('admin.internal.cms-roles.')
    ->middleware(['auth', EnsureCmsStaff::class, EnsureCmsPermission::class.':roles.manage', 'throttle:40,1'])
    ->group(static function (): void {
        Route::get('/', [CmsCustomRoleController::class, 'index'])->name('index');
        Route::get('/catalog', [CmsCustomRoleController::class, 'catalog'])->name('catalog');
        Route::post('/', [CmsCustomRoleController::class, 'store'])->name('store');
        Route::get('/{role}', [CmsCustomRoleController::class, 'show'])->name('show');
        Route::put('/{role}', [CmsCustomRoleController::class, 'update'])->name('update');
        Route::put('/{role}/permissions', [CmsCustomRoleController::class, 'syncPermissions'])->name('permissions');
        Route::delete('/{role}', [CmsCustomRoleController::class, 'destroy'])->name('destroy');
    });
