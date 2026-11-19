<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\BablysAdminHomeController;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

/**
 * This file is registered exactly once by BablysCmsAuthServiceProvider.
 * It is wrapped in the web middleware group by that provider.
 * No staff-only CRUD endpoints exist in P2B.2.
 */
Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', EnsureCmsStaff::class])
    ->group(static function (): void {
        Route::get('/', BablysAdminHomeController::class)->name('index');
    });

// Bablys P2B.3A - owner-only staff JSON endpoints.
require __DIR__.'/bablys_admin_staff.php';

// Bablys P2B.3B - owner-only Inertia staff screen and secure setup-link action.
require __DIR__.'/bablys_admin_staff_ui.php';

// Bablys P2B.4A - owner-only, backend role management JSON API.
require __DIR__.'/bablys_admin_roles.php';

// Bablys P2B.4B - owner-only roles management screen.
require __DIR__ . '/bablys_admin_roles_ui.php';

// Bablys P2B.5C â€” protected read-only audit viewer.
require __DIR__ . '/bablys_admin_audit.php';
