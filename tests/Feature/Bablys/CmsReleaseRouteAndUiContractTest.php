<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** P2B.6C: identity, Inertia page resolution, Fortify and protected route inventory. */
final class CmsReleaseRouteAndUiContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_owner_can_render_all_four_admin_inertia_pages(): void
    {
        $owner = $this->withRole('super_admin');
        $pages = [
            '/admin' => 'admin/index',
            '/admin/staff-users' => 'admin/staff-users/index',
            '/admin/roles' => 'admin/roles/index',
            '/admin/audit-logs' => 'admin/audit-logs/index',
        ];
        foreach ($pages as $url => $component) {
            $this->actingAs($owner)->get($url)->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component));
        }
    }

    public function test_guest_customer_and_regular_staff_are_excluded_from_owner_modules(): void
    {
        foreach (['/admin/staff-users', '/admin/roles', '/admin/audit-logs'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        foreach ([User::factory()->create(), $this->withRole('content_editor')] as $actor) {
            foreach (['/admin/staff-users', '/admin/roles', '/admin/audit-logs'] as $url) {
                $this->actingAs($actor)->get($url)->assertForbidden();
            }
            foreach (['/admin/internal/staff-users', '/admin/internal/cms-roles', '/admin/internal/audit-logs'] as $url) {
                $this->actingAs($actor)->getJson($url)->assertForbidden();
            }
        }
    }

    public function test_staff_and_audit_named_routes_keep_both_authentication_and_permission_guards(): void
    {
        $expected = [
            'admin.staff-users.index' => 'roles.manage',
            'admin.roles.index' => 'roles.manage',
            'admin.audit-logs.index' => 'audit.view',
            'admin.internal.staff-users.roles' => 'roles.manage',
            'admin.internal.cms-roles.store' => 'roles.manage',
            'admin.internal.audit-logs.show' => 'audit.view',
        ];
        foreach ($expected as $routeName => $permission) {
            $route = RouteFacade::getRoutes()->getByName($routeName);
            self::assertInstanceOf(Route::class, $route, 'Missing '.$routeName);
            $middleware = $route->gatherMiddleware();
            self::assertContains('auth', $middleware);
            self::assertContains(EnsureCmsStaff::class, $middleware);
            self::assertContains(EnsureCmsPermission::class.':'.$permission, $middleware);
        }
    }

    public function test_fortify_onboarding_and_password_security_routes_remain_registered(): void
    {
        foreach (['login', 'register', 'password.request', 'password.reset', 'verification.notice', 'verification.verify'] as $name) {
            self::assertTrue(RouteFacade::has($name), 'Missing Fortify route: '.$name);
        }
        self::assertTrue(RouteFacade::has('dashboard'));
        self::assertTrue(RouteFacade::has('security.edit'));
    }

    public function test_audit_viewer_has_no_mutating_http_routes(): void
    {
        $found = 0;
        foreach (RouteFacade::getRoutes() as $route) {
            if (! $route instanceof Route || ! str_starts_with($route->uri(), 'admin/internal/audit-logs')) {
                continue;
            }
            $found++;
            foreach ($route->methods() as $method) {
                self::assertContains($method, ['GET', 'HEAD'], 'Unexpected audit write route: '.$route->uri());
            }
        }
        self::assertGreaterThanOrEqual(3, $found);
    }

    private function withRole(string $key): User
    {
        $user = User::factory()->create();
        $role = CmsRole::query()->where('key', $key)->firstOrFail();
        $role->users()->attach($user->getKey());

        return $user;
    }
}
