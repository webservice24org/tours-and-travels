<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Travel\DestinationsPageController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// Included once by routes/bablys_admin.php, with no public or alternative write routes.
Route::get('/admin/destinations', DestinationsPageController::class)
    ->middleware(['auth', EnsureCmsStaff::class, EnsureCmsPermission::class.':taxonomy.manage'])
    ->name('admin.destinations.index');
