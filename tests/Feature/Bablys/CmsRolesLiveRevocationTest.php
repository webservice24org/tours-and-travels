<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Access\CmsAccess;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/** P2B.4C — deny access immediately after database-backed privilege changes. */
final class CmsRolesLiveRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_replacing_permissions_revokes_old_grant_without_a_new_login(): void
    {
        $owner = $this->withRole('super_admin');
        $role = $this->createCustomRole($owner, 'custom_operations', ['tours.view']);
        $staff = User::factory()->create();
        $role->users()->attach($staff->getKey());
        self::assertTrue(Gate::forUser($staff)->allows('tours.view'));
        self::assertFalse(Gate::forUser($staff)->allows('bookings.view'));

        $this->actingAs($owner)->putJson('/admin/internal/cms-roles/'.$role->getKey().'/permissions', [
            'permission_keys' => ['bookings.view'],
        ])->assertOk()->assertJsonPath('data.permission_keys', ['bookings.view']);

        self::assertFalse(Gate::forUser($staff)->allows('tours.view'));
        self::assertTrue(Gate::forUser($staff)->allows('bookings.view'));
        $this->actingAs($staff)->get('/admin')->assertOk();
    }

    public function test_revoking_last_permission_disables_admin_access_while_preserving_role_assignment(): void
    {
        $owner = $this->withRole('super_admin');
        $role = $this->createCustomRole($owner, 'custom_readonly', ['tours.view']);
        $staff = User::factory()->create();
        $role->users()->attach($staff->getKey());
        $this->actingAs($staff)->get('/admin')->assertOk();

        $this->actingAs($owner)->putJson('/admin/internal/cms-roles/'.$role->getKey().'/permissions', [
            'permission_keys' => [],
        ])->assertOk()->assertJsonPath('data.permission_keys', []);

        self::assertFalse(Gate::forUser($staff)->allows('tours.view'));
        $this->actingAs($staff)->get('/admin')->assertForbidden();
        $this->assertDatabaseHas('cms_role_user', [
            'user_id' => $staff->getKey(), 'role_id' => $role->getKey(),
        ]);
    }

    public function test_staff_suspension_revokes_gate_and_request_access_without_removing_the_role(): void
    {
        $owner = $this->withRole('super_admin');
        $staff = $this->withRole('booking_agent');
        self::assertTrue(Gate::forUser($staff)->allows('bookings.view'));

        $this->actingAs($owner)->patchJson('/admin/internal/staff-users/'.$staff->getKey().'/access', [
            'enabled' => false,
        ])->assertOk()->assertJsonPath('data.cms_staff_enabled', false);

        // Intentionally use the existing in-memory user: eligibility must consult current DB state.
        self::assertFalse(Gate::forUser($staff)->allows('bookings.view'));
        $this->actingAs($staff)->get('/admin')->assertForbidden();
        $role = CmsRole::query()->where('key', 'booking_agent')->firstOrFail();
        $this->assertDatabaseHas('cms_role_user', [
            'user_id' => $staff->getKey(), 'role_id' => $role->getKey(),
        ]);

        $this->actingAs($owner)->patchJson('/admin/internal/staff-users/'.$staff->getKey().'/access', [
            'enabled' => true,
        ])->assertOk();
        self::assertTrue(Gate::forUser($staff)->allows('bookings.view'));
    }

    public function test_email_unverification_revokes_permissions_even_with_valid_role_and_session(): void
    {
        $staff = $this->withRole('seo_editor');
        self::assertTrue(Gate::forUser($staff)->allows('seo.edit'));

        DB::table('users')->where('id', $staff->getKey())->update(['email_verified_at' => null]);

        self::assertFalse(Gate::forUser($staff)->allows('seo.edit'));
        $this->actingAs($staff)->get('/admin')->assertForbidden();
    }

    public function test_multiple_roles_retain_other_grants_when_one_role_loses_permission(): void
    {
        $owner = $this->withRole('super_admin');
        $tours = $this->createCustomRole($owner, 'custom_tour_reader', ['tours.view']);
        $bookings = $this->createCustomRole($owner, 'custom_booking_reader', ['bookings.view']);
        $staff = User::factory()->create();
        $tours->users()->attach($staff->getKey());
        $bookings->users()->attach($staff->getKey());

        self::assertTrue(Gate::forUser($staff)->allows('tours.view'));
        self::assertTrue(Gate::forUser($staff)->allows('bookings.view'));

        $this->actingAs($owner)->putJson('/admin/internal/cms-roles/'.$tours->getKey().'/permissions', [
            'permission_keys' => [],
        ])->assertOk();

        self::assertFalse(Gate::forUser($staff)->allows('tours.view'));
        self::assertTrue(Gate::forUser($staff)->allows('bookings.view'));
        self::assertTrue(app(CmsAccess::class)->canEnterAdmin($staff));
    }

    public function test_role_removal_revokes_every_permission_immediately_and_reassignment_restores_valid_grants(): void
    {
        $owner = $this->withRole('super_admin');
        $staff = $this->withRole('content_editor');
        self::assertTrue(Gate::forUser($staff)->allows('blog.edit'));

        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$staff->getKey().'/roles', [
            'role_keys' => [],
        ])->assertOk()->assertJsonPath('data.role_keys', []);

        self::assertFalse(Gate::forUser($staff)->allows('blog.edit'));
        $this->actingAs($staff)->get('/admin')->assertForbidden();
        $this->assertDatabaseMissing('cms_role_user', ['user_id' => $staff->getKey()]);

        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$staff->getKey().'/roles', [
            'role_keys' => ['booking_agent'],
        ])->assertOk()->assertJsonPath('data.role_keys', ['booking_agent']);
        self::assertTrue(Gate::forUser($staff)->allows('bookings.view'));
        self::assertFalse(Gate::forUser($staff)->allows('blog.edit'));
    }

    private function createCustomRole(User $owner, string $key, array $permissions): CmsRole
    {
        $response = $this->actingAs($owner)->postJson('/admin/internal/cms-roles', [
            'key' => $key,
            'name' => 'Custom QA Role',
            'description' => 'Security regression coverage',
            'permission_keys' => $permissions,
        ])->assertCreated();

        return CmsRole::query()->findOrFail((int) $response->json('data.id'));
    }

    private function withRole(string $key): User
    {
        $user = User::factory()->create();
        $role = CmsRole::query()->where('key', $key)->firstOrFail();
        $role->users()->attach($user->getKey());
        return $user;
    }
}
