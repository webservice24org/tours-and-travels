<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Travel\ToursPageController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// Included once by the existing routes/bablys_admin.php registry.
Route::get('/admin/tours', ToursPageController::class)
    ->middleware(['auth', EnsureCmsStaff::class, EnsureCmsPermission::class.':tours.view'])
    ->name('admin.tours.index');
