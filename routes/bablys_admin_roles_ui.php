<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Roles\CmsRolesPageController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// Owner identity is checked again inside CmsRolesPageController.
Route::get('/admin/roles', CmsRolesPageController::class)
    ->middleware(['auth', EnsureCmsStaff::class, EnsureCmsPermission::class.':roles.manage'])
    ->name('admin.roles.index');
