<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Audit\CmsAuditFeedController;
use App\Http\Controllers\Admin\Audit\CmsAuditPageController;
use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Support\Facades\Route;

// Loaded exactly once from Bablys' web-middleware admin route fragment.
// All endpoints are read-only; owner identity is checked again in the reader.
Route::middleware(['auth', EnsureCmsStaff::class, EnsureCmsPermission::class.':audit.view', 'throttle:90,1'])
    ->group(static function (): void {
        Route::get('/admin/audit-logs', CmsAuditPageController::class)
            ->name('admin.audit-logs.index');
        Route::get('/admin/internal/audit-logs', [CmsAuditFeedController::class, 'index'])
            ->name('admin.internal.audit-logs.index');
        Route::get('/admin/internal/audit-logs/options', [CmsAuditFeedController::class, 'options'])
            ->name('admin.internal.audit-logs.options');
        Route::get('/admin/internal/audit-logs/{event}', [CmsAuditFeedController::class, 'show'])
            ->whereUuid('event')
            ->name('admin.internal.audit-logs.show');
    });
