<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Staff\CmsStaffUserController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// Loaded from the P2B.2 Bablys admin route file, in its existing 'web' group.
// This is intentionally JSON-only. P2B.3B will provide responsive TSX pages.
Route::prefix('admin/internal/staff-users')
    ->name('admin.internal.staff-users.')
    ->middleware(['auth', EnsureCmsStaff::class, EnsureCmsPermission::class.':roles.manage', 'throttle:60,1'])
    ->group(static function (): void {
        Route::get('/', [CmsStaffUserController::class, 'index'])->name('index');
        Route::post('/', [CmsStaffUserController::class, 'store'])->name('store');
        Route::get('/{user}', [CmsStaffUserController::class, 'show'])->name('show');
        Route::put('/{user}', [CmsStaffUserController::class, 'update'])->name('update');
        Route::put('/{user}/roles', [CmsStaffUserController::class, 'syncRoles'])->name('roles');
        Route::patch('/{user}/access', [CmsStaffUserController::class, 'setAccess'])->name('access');
    });
