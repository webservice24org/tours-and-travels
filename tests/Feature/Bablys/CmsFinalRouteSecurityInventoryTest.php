<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/** P2B.6A — route inventory guardrails against later accidental exposure. */
final class CmsFinalRouteSecurityInventoryTest extends TestCase
{
    public function test_all_bablys_admin_routes_retain_authentication_and_staff_middleware(): void
    {
        $checked = 0;
        foreach (RouteFacade::getRoutes() as $route) {
            if (! $route instanceof Route || ! str_starts_with($route->uri(), 'admin')) {
                continue;
            }
            $checked++;
            $middleware = $route->gatherMiddleware();
            self::assertContains('auth', $middleware, "Missing auth: {$route->uri()}");
            self::assertContains(EnsureCmsStaff::class, $middleware, "Missing staff check: {$route->uri()}");
        }
        self::assertGreaterThanOrEqual(15, $checked, 'Expected Bablys admin routes were not registered.');
    }

    public function test_sensitive_named_routes_have_permission_middleware(): void
    {
        $expected = [
            'admin.staff-users.index' => 'roles.manage',
            'admin.roles.index' => 'roles.manage',
            'admin.audit-logs.index' => 'audit.view',
            'admin.internal.staff-users.roles' => 'roles.manage',
            'admin.internal.cms-roles.store' => 'roles.manage',
            'admin.internal.audit-logs.show' => 'audit.view',
        ];
        foreach ($expected as $name => $permission) {
            $route = RouteFacade::getRoutes()->getByName($name);
            self::assertInstanceOf(Route::class, $route, "Missing protected route: {$name}");
            self::assertContains(
                EnsureCmsPermission::class.':'.$permission,
                $route->gatherMiddleware(),
                "Missing permission check on {$name}",
            );
        }
    }

    public function test_audit_http_routes_are_read_only_and_never_register_mutation_methods(): void
    {
        $found = 0;
        foreach (RouteFacade::getRoutes() as $route) {
            if (! $route instanceof Route || ! str_starts_with($route->uri(), 'admin/internal/audit-logs')) {
                continue;
            }
            $found++;
            foreach ($route->methods() as $method) {
                self::assertContains($method, ['GET', 'HEAD'], "Audit mutation route exposed: {$route->uri()} {$method}");
            }
        }
        self::assertGreaterThanOrEqual(3, $found);
    }

    public function test_privileged_write_and_audit_api_routes_are_rate_limited(): void
    {
        $names = [
            'admin.internal.staff-users.setup-link',
            'admin.internal.staff-users.roles',
            'admin.internal.cms-roles.store',
            'admin.internal.audit-logs.index',
        ];
        foreach ($names as $name) {
            $route = RouteFacade::getRoutes()->getByName($name);
            self::assertInstanceOf(Route::class, $route, "Missing rate-limited route: {$name}");
            $middleware = $route->gatherMiddleware();
            $hasThrottle = false;
            foreach ($middleware as $item) {
                if (is_string($item) && str_starts_with($item, 'throttle:')) {
                    $hasThrottle = true;
                    break;
                }
            }
            self::assertTrue($hasThrottle, "No throttle on {$name}");
        }
    }
}
