<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsPermission;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P2B.6A — privileged routes must remain owner-only across all three CMS modules. */
final class CmsFinalCrossSurfaceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_guests_cannot_reach_staff_roles_or_audit_surfaces(): void
    {
        foreach (['/admin', '/admin/staff-users', '/admin/roles', '/admin/audit-logs'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
        foreach (['/admin/internal/staff-users', '/admin/internal/cms-roles', '/admin/internal/audit-logs'] as $uri) {
            $this->getJson($uri)->assertUnauthorized();
        }
    }

    public function test_registered_customer_cannot_reach_any_privileged_surface(): void
    {
        $customer = User::factory()->create();
        foreach (['/admin', '/admin/staff-users', '/admin/roles', '/admin/audit-logs'] as $uri) {
            $this->actingAs($customer)->get($uri)->assertForbidden();
        }
        foreach (['/admin/internal/staff-users', '/admin/internal/cms-roles', '/admin/internal/audit-logs'] as $uri) {
            $this->actingAs($customer)->getJson($uri)->assertForbidden();
        }
    }

    public function test_regular_staff_can_enter_admin_but_cannot_manage_staff_roles_or_audits(): void
    {
        $editor = $this->withRole('content_editor');
        $this->actingAs($editor)->get('/admin')->assertOk();

        foreach (['/admin/staff-users', '/admin/roles', '/admin/audit-logs'] as $uri) {
            $this->actingAs($editor)->get($uri)->assertForbidden();
        }
        foreach (['/admin/internal/staff-users', '/admin/internal/cms-roles', '/admin/internal/audit-logs'] as $uri) {
            $this->actingAs($editor)->getJson($uri)->assertForbidden();
        }
    }

    public function test_forged_owner_permissions_do_not_bypass_identity_checks(): void
    {
        $editor = $this->withRole('content_editor');
        $role = CmsRole::query()->where('key', 'content_editor')->firstOrFail();
        $forgedIds = CmsPermission::query()->whereIn('key', ['roles.manage', 'audit.view'])
            ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        self::assertCount(2, $forgedIds);
        $role->permissions()->syncWithoutDetaching($forgedIds);

        // A manually tampered pivot is not an authorized super-admin appointment.
        $this->actingAs($editor)->get('/admin/staff-users')->assertForbidden();
        $this->actingAs($editor)->get('/admin/roles')->assertForbidden();
        $this->actingAs($editor)->get('/admin/audit-logs')->assertForbidden();
        $this->actingAs($editor)->getJson('/admin/internal/staff-users')->assertForbidden();
        $this->actingAs($editor)->getJson('/admin/internal/cms-roles')->assertForbidden();
        $this->actingAs($editor)->getJson('/admin/internal/audit-logs')->assertForbidden();

        $this->actingAs($editor)->postJson('/admin/internal/cms-roles', [
            'key' => 'custom_illicit',
            'name' => 'Illicit',
            'description' => null,
            'permission_keys' => ['tours.view'],
        ])->assertForbidden();
        $this->assertDatabaseMissing('cms_roles', ['key' => 'custom_illicit']);
    }

    public function test_suspending_an_owner_revokes_every_admin_surface_with_existing_user_instance(): void
    {
        $owner = $this->withRole('super_admin');
        $this->actingAs($owner)->get('/admin/roles')->assertOk();
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs')->assertOk();

        DB::table('users')->where('id', $owner->getKey())->update(['cms_staff_disabled_at' => now()]);

        foreach (['/admin', '/admin/staff-users', '/admin/roles', '/admin/audit-logs'] as $uri) {
            $this->actingAs($owner)->get($uri)->assertForbidden();
        }
        foreach (['/admin/internal/staff-users', '/admin/internal/cms-roles', '/admin/internal/audit-logs'] as $uri) {
            $this->actingAs($owner)->getJson($uri)->assertForbidden();
        }
    }

    public function test_unverifying_an_owner_revokes_every_admin_surface_on_next_request(): void
    {
        $owner = $this->withRole('super_admin');
        $this->actingAs($owner)->get('/admin/roles')->assertOk();

        DB::table('users')->where('id', $owner->getKey())->update(['email_verified_at' => null]);

        foreach (['/admin', '/admin/staff-users', '/admin/roles', '/admin/audit-logs'] as $uri) {
            $this->actingAs($owner)->get($uri)->assertForbidden();
        }
    }

    public function test_privileged_owner_can_read_all_three_admin_modules(): void
    {
        $owner = $this->withRole('super_admin');
        $this->actingAs($owner)->get('/admin')->assertOk();
        $this->actingAs($owner)->get('/admin/staff-users')->assertOk();
        $this->actingAs($owner)->get('/admin/roles')->assertOk();
        $this->actingAs($owner)->get('/admin/audit-logs')->assertOk();
        $this->actingAs($owner)->getJson('/admin/internal/staff-users')->assertOk();
        $this->actingAs($owner)->getJson('/admin/internal/cms-roles')->assertOk();
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs')->assertOk();
    }

    private function withRole(string $key): User
    {
        $user = User::factory()->create();
        $role = CmsRole::query()->where('key', $key)->firstOrFail();
        $role->users()->attach($user->getKey());

        return $user;
    }
}
