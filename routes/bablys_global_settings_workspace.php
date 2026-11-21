<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Cms\BablysGlobalSettingsWorkspaceController as Workspace;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureCmsStaff::class, EnsureCmsPermission::class.':settings.manage'])
    ->prefix('admin/global-settings')->name('admin.global-settings.')
    ->group(static function (): void {
        Route::get('/', [Workspace::class, 'overview'])->name('overview');
        Route::get('/header', [Workspace::class, 'header'])->name('header');
        Route::get('/footer', [Workspace::class, 'footer'])->name('footer');
        Route::put('/header/settings', [Workspace::class, 'saveHeaderSettings'])->middleware('throttle:20,1')->name('header.settings.save');
        Route::put('/footer/settings', [Workspace::class, 'saveFooterSettings'])->middleware('throttle:20,1')->name('footer.settings.save');
        Route::post('/header/settings/publish', [Workspace::class, 'publishHeaderSettings'])->middleware('throttle:10,1')->name('header.settings.publish');
        Route::post('/footer/settings/publish', [Workspace::class, 'publishFooterSettings'])->middleware('throttle:10,1')->name('footer.settings.publish');
        Route::post('/{section}/menus', [Workspace::class, 'createMenu'])
            ->whereIn('section', ['header', 'footer'])->middleware('throttle:15,1')->name('menus.create');
        Route::put('/{section}/menus/{menu}', [Workspace::class, 'saveMenu'])
            ->whereIn('section', ['header', 'footer'])->whereNumber('menu')->middleware('throttle:30,1')->name('menus.save');
        Route::post('/{section}/menus/{menu}/publish', [Workspace::class, 'publishMenu'])
            ->whereIn('section', ['header', 'footer'])->whereNumber('menu')->middleware('throttle:10,1')->name('menus.publish');
    });
