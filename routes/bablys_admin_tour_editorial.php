<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Travel\TourEditorialController;
use App\Http\Controllers\Admin\Travel\TourEditorPageController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureCmsStaff::class])->group(static function (): void {
    Route::get('/admin/tours/create', [TourEditorPageController::class, 'create'])
        ->middleware(EnsureCmsPermission::class.':tours.create')->name('admin.tours.create');
    Route::get('/admin/tours/{tour}/edit', [TourEditorPageController::class, 'edit'])
        ->middleware(EnsureCmsPermission::class.':tours.edit')->name('admin.tours.edit');

    Route::prefix('admin/internal')->middleware('throttle:60,1')->group(static function (): void {
        Route::get('tour-tags/suggest', [TourEditorialController::class, 'suggestions'])
            ->middleware(EnsureCmsPermission::class.':tours.view')->name('admin.tour-tags.suggest');
        Route::get('tours/{tour}/media', [TourEditorialController::class, 'index'])
            ->middleware(EnsureCmsPermission::class.':tours.view')->name('admin.tours.media.index');
        Route::post('tours/{tour}/media', [TourEditorialController::class, 'store'])
            ->middleware(EnsureCmsPermission::class.':tours.edit')->name('admin.tours.media.store');
        Route::patch('tours/{tour}/media/{media}', [TourEditorialController::class, 'update'])
            ->middleware(EnsureCmsPermission::class.':tours.edit')->name('admin.tours.media.update');
        Route::delete('tours/{tour}/media/{media}', [TourEditorialController::class, 'destroy'])
            ->middleware(EnsureCmsPermission::class.':tours.edit')->name('admin.tours.media.destroy');
    });
});
