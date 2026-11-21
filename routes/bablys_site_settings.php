<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Cms\BablysSiteSettingsController;
use App\Http\Controllers\Public\BablysPublicSiteSettingsController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// P4A.1: the public URL returns ONLY the published snapshot (defaults for now).
Route::get('/api/public/site-settings', BablysPublicSiteSettingsController::class)
    ->name('bablys.public.site-settings');

Route::middleware(['auth', EnsureCmsStaff::class, EnsureCmsPermission::class.':settings.manage'])
    ->group(static function (): void {
        Route::get('/admin/site-settings', [BablysSiteSettingsController::class, 'index'])
            ->name('admin.site-settings.index');
        Route::put('/admin/site-settings', [BablysSiteSettingsController::class, 'update'])
            ->middleware('throttle:20,1')
            ->name('admin.site-settings.update');
    });
