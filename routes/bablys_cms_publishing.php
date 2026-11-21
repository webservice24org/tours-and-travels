<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Cms\BablysCmsPublishingController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// P4A.3 does not rename P4A.1/P4A.2 routes or alter their original contracts.
Route::middleware(['auth', EnsureCmsStaff::class, EnsureCmsPermission::class.':settings.manage'])
    ->prefix('admin/cms-publishing')->name('admin.cms-publishing.')
    ->group(static function (): void {
        Route::get('/', [BablysCmsPublishingController::class, 'index'])->name('index');
        Route::get('/site-settings/preview', [BablysCmsPublishingController::class, 'previewSettings'])->name('settings.preview');
        Route::post('/site-settings/publish', [BablysCmsPublishingController::class, 'publishSettings'])
            ->middleware('throttle:10,1')->name('settings.publish');
        Route::post('/site-settings/rollback', [BablysCmsPublishingController::class, 'rollbackSettings'])
            ->middleware('throttle:5,1')->name('settings.rollback');
        Route::get('/navigation-menus/{menu}/preview', [BablysCmsPublishingController::class, 'previewMenu'])
            ->whereNumber('menu')->name('navigation.preview');
        Route::post('/navigation-menus/{menu}/publish', [BablysCmsPublishingController::class, 'publishMenu'])
            ->whereNumber('menu')->middleware('throttle:10,1')->name('navigation.publish');
        Route::post('/navigation-menus/{menu}/rollback', [BablysCmsPublishingController::class, 'rollbackMenu'])
            ->whereNumber('menu')->middleware('throttle:5,1')->name('navigation.rollback');
    });
