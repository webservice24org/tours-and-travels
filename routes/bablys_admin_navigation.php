<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Navigation\CmsAdminNavigationController;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// P3B.5: a read-only, current-user navigation feed; no public or write route.
Route::get('/admin/internal/navigation', CmsAdminNavigationController::class)
    ->middleware(['auth', EnsureCmsStaff::class])
    ->name('admin.navigation.show');
