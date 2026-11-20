<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Travel\TourCategoriesPageController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// Registered once by routes/bablys_admin.php. No public route or new API bypass.
Route::get('/admin/tour-categories', TourCategoriesPageController::class)
    ->middleware(['auth', EnsureCmsStaff::class, EnsureCmsPermission::class.':taxonomy.manage'])
    ->name('admin.tour-categories.index');
