<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Staff\CmsStaffUsersPageController;
use App\Http\Controllers\Admin\Staff\SendCmsStaffSetupLinkController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// Loaded once from Bablys' existing web-middleware admin route fragment.
// Both endpoints are additionally checked for actual super_admin membership
// by CmsStaffUserManager::assertOwner().
Route::middleware(['auth', EnsureCmsStaff::class, EnsureCmsPermission::class.':roles.manage'])
    ->group(static function (): void {
        Route::get('/admin/staff-users', CmsStaffUsersPageController::class)
            ->name('admin.staff-users.index');

        Route::post('/admin/internal/staff-users/{user}/setup-link', SendCmsStaffSetupLinkController::class)
            ->middleware('throttle:6,1')
            ->name('admin.internal.staff-users.setup-link');
    });
