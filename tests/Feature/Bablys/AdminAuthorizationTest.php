<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Http\Middleware\EnsureCmsPermission;
use App\Http\Middleware\EnsureCmsStaff;
use App\Models\Cms\CmsPermission;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_guest_must_login_to_enter_admin(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_ordinary_registered_user_is_denied_admin(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_unverified_staff_member_is_denied_admin(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $this->assignRole($user, 'booking_agent');
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_scoped_staff_sees_only_granted_permissions(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, 'booking_agent');

        $this->actingAs($user)->get('/admin')->assertOk()->assertInertia(
            fn (Assert $page) => $page
                ->component('admin/index')
                ->where('roles.0', 'booking_agent')
                ->where('permissions.0', 'bookings.manage')
                ->where('permissions.1', 'bookings.view')
                ->missing('permissions.2')
        );
    }

    public function test_gates_are_permission_specific_and_default_deny(): void
    {
        $agent = User::factory()->create();
        $this->assignRole($agent, 'booking_agent');
        self::assertTrue(Gate::forUser($agent)->allows('bookings.view'));
        self::assertTrue(Gate::forUser($agent)->allows('bookings.manage'));
        self::assertFalse(Gate::forUser($agent)->allows('reviews.moderate'));
        self::assertFalse(Gate::forUser($agent)->allows('roles.manage'));
        self::assertFalse(Gate::forUser($agent)->allows('unknown.admin_permission'));
    }

    public function test_revoked_permission_is_enforced_without_new_login(): void
    {
        $user = User::factory()->create();
        $role = $this->assignRole($user, 'administrator');
        $permission = CmsPermission::query()->where('key', 'tours.publish')->firstOrFail();

        self::assertTrue(Gate::forUser($user)->allows('tours.publish'));
        $role->permissions()->detach($permission->getKey());
        self::assertFalse(Gate::forUser($user)->allows('tours.publish'));
    }

    public function test_request_permission_middleware_denies_then_allows(): void
    {
        // Test-only route: never shipped as a production endpoint.
        Route::get('/__bablys_permission_test', static fn () => response('allowed'))
            ->middleware(['web', 'auth', EnsureCmsStaff::class, EnsureCmsPermission::class.':tours.publish']);

        $editor = User::factory()->create();
        $this->assignRole($editor, 'content_editor');
        $this->actingAs($editor)->get('/__bablys_permission_test')->assertForbidden();

        $administrator = User::factory()->create();
        $this->assignRole($administrator, 'administrator');
        $this->actingAs($administrator)->get('/__bablys_permission_test')->assertOk()->assertSee('allowed');
    }

    public function test_role_and_permission_policies_keep_writes_closed(): void
    {
        $owner = User::factory()->create();
        $this->assignRole($owner, 'super_admin');

        self::assertTrue(Gate::forUser($owner)->allows('viewAny', CmsRole::class));
        self::assertTrue(Gate::forUser($owner)->allows('viewAny', CmsPermission::class));
        self::assertFalse(Gate::forUser($owner)->allows('create', CmsRole::class));
        self::assertFalse(Gate::forUser($owner)->allows('create', CmsPermission::class));
    }

    private function assignRole(User $user, string $key): CmsRole
    {
        $role = CmsRole::query()->where('key', $key)->firstOrFail();
        $role->users()->attach($user->getKey());

        return $role;
    }
}
