<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Cms\Access\CmsAccess;
use App\Domain\Cms\Access\RbacCatalog;
use App\Models\Cms\CmsPermission;
use App\Models\Cms\CmsRole;
use App\Models\User;
use App\Policies\Cms\CmsPermissionPolicy;
use App\Policies\Cms\CmsRolePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use LogicException;

/**
 * Dedicated Bablys module provider; no edits to existing AppServiceProvider
 * or existing public / auth routes are needed.
 */
final class BablysCmsAuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (array_keys(RbacCatalog::permissions()) as $permission) {
            if (Gate::has($permission)) {
                throw new LogicException("Bablys permission gate collision: {$permission}");
            }

            Gate::define($permission, static function (User $user) use ($permission): bool {
                return app(CmsAccess::class)->allows($user, $permission);
            });
        }

        Gate::policy(CmsRole::class, CmsRolePolicy::class);
        Gate::policy(CmsPermission::class, CmsPermissionPolicy::class);

        // Laravel route:cache includes these routes during cache compilation.
        // Cached route runtime must not re-register them.
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group(base_path('routes/bablys_admin.php'));
        }
    }
}
