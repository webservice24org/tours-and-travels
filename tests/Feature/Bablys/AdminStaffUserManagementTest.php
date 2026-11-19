<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsPermission;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AdminStaffUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_guests_and_ordinary_users_cannot_read_staff_data(): void
    {
        $this->getJson('/admin/internal/staff-users')->assertUnauthorized();

        $customer = User::factory()->create();
        $this->actingAs($customer)->getJson('/admin/internal/staff-users')->assertForbidden();

        $agent = $this->withRole('booking_agent');
        $this->actingAs($agent)->getJson('/admin/internal/staff-users')->assertForbidden();
    }

    public function test_owner_can_list_users_without_exposing_credentials(): void
    {
        $owner = $this->withRole('super_admin');
        User::factory()->create(['name' => 'Example Traveler', 'email' => 'traveler@bablys.test']);

        $this->actingAs($owner)
            ->getJson('/admin/internal/staff-users?search=traveler&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'traveler@bablys.test')
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token');
    }

    public function test_non_super_role_cannot_use_user_api_even_if_given_roles_manage_grant(): void
    {
        $administrator = $this->withRole('administrator');
        $role = CmsRole::query()->where('key', 'administrator')->firstOrFail();
        $permission = CmsPermission::query()->where('key', 'roles.manage')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $this->actingAs($administrator)
            ->getJson('/admin/internal/staff-users')
            ->assertForbidden();
    }

    public function test_staff_creation_is_unverified_and_uses_an_unshared_random_password(): void
    {
        $owner = $this->withRole('super_admin');
        $response = $this->actingAs($owner)->postJson('/admin/internal/staff-users', [
            'name' => 'New Staff',
            'email' => 'newstaff@bablys.test',
            'role_keys' => ['content_editor'],
            'password' => 'InjectedPassword123!',
            'email_verified_at' => now()->toDateTimeString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.role_keys.0', 'content_editor')
            ->assertJsonMissingPath('data.password');

        $staff = User::query()->where('email', 'newstaff@bablys.test')->firstOrFail();
        self::assertNull($staff->email_verified_at);
        self::assertFalse(Hash::check('InjectedPassword123!', $staff->password));
        $this->actingAs($staff)->get('/admin')->assertForbidden();
    }

    public function test_non_owner_cannot_mutate_even_if_default_staff_member(): void
    {
        $admin = $this->withRole('administrator');
        $target = User::factory()->create();
        $this->actingAs($admin)->putJson('/admin/internal/staff-users/'.$target->id.'/roles', [
            'role_keys' => ['administrator'],
        ])->assertForbidden();
        $this->assertDatabaseMissing('cms_role_user', ['user_id' => $target->id]);
    }

    public function test_super_admin_role_cannot_be_granted_via_staff_api(): void
    {
        $owner = $this->withRole('super_admin');
        $target = User::factory()->create();
        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$target->id.'/roles', [
            'role_keys' => ['super_admin'],
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('cms_role_user', ['user_id' => $target->id]);
    }

    public function test_owner_cannot_edit_demote_or_suspend_self_or_other_super_admin(): void
    {
        $owner = $this->withRole('super_admin');
        $other = $this->withRole('super_admin');

        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$owner->id.'/roles', [
            'role_keys' => [],
        ])->assertUnprocessable();

        $this->actingAs($owner)->patchJson('/admin/internal/staff-users/'.$other->id.'/access', [
            'enabled' => false,
        ])->assertUnprocessable();

        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$other->id, [
            'name' => 'Hijacked', 'email' => 'newowner@bablys.test',
        ])->assertUnprocessable();

        self::assertTrue(Gate::forUser($owner)->allows('roles.manage'));
        self::assertTrue(Gate::forUser($other)->allows('roles.manage'));
    }

    public function test_disabling_staff_revokes_cms_access_immediately_and_reenabling_restores_it(): void
    {
        $owner = $this->withRole('super_admin');
        $staff = $this->withRole('booking_agent');
        $this->actingAs($staff)->get('/admin')->assertOk();

        $this->actingAs($owner)->patchJson('/admin/internal/staff-users/'.$staff->id.'/access', [
            'enabled' => false,
        ])->assertOk()->assertJsonPath('data.cms_staff_enabled', false);

        $staff->refresh();
        self::assertNotNull($staff->cms_staff_disabled_at);
        self::assertFalse(Gate::forUser($staff)->allows('bookings.view'));
        $this->actingAs($staff)->get('/admin')->assertForbidden();

        $this->actingAs($owner)->patchJson('/admin/internal/staff-users/'.$staff->id.'/access', [
            'enabled' => true,
        ])->assertOk()->assertJsonPath('data.cms_staff_enabled', true);

        $staff->refresh();
        self::assertNull($staff->cms_staff_disabled_at);
        self::assertTrue(Gate::forUser($staff)->allows('bookings.view'));
    }

    public function test_changing_staff_email_forces_reverification(): void
    {
        $owner = $this->withRole('super_admin');
        $staff = $this->withRole('content_editor');
        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$staff->id, [
            'name' => 'Updated Editor',
            'email' => 'updated-editor@bablys.test',
        ])->assertOk()->assertJsonPath('data.email_verified', false);

        $staff->refresh();
        self::assertNull($staff->email_verified_at);
        self::assertFalse(Gate::forUser($staff)->allows('blog.edit'));
    }

    public function test_removing_roles_turns_staff_into_regular_customer(): void
    {
        $owner = $this->withRole('super_admin');
        $staff = $this->withRole('content_editor');
        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$staff->id.'/roles', [
            'role_keys' => [],
        ])->assertOk()->assertJsonPath('data.role_keys', []);

        $this->assertDatabaseMissing('cms_role_user', ['user_id' => $staff->id]);
        $this->actingAs($staff)->get('/admin')->assertForbidden();
    }

    public function test_malformed_or_unauthorized_staff_updates_do_not_change_roles(): void
    {
        $owner = $this->withRole('super_admin');
        $staff = $this->withRole('booking_agent');
        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$staff->id.'/roles', [
            'role_keys' => ['booking_agent', 'booking_agent'],
        ])->assertUnprocessable();
        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$staff->id.'/roles', [
            'role_keys' => ['nonexistent'],
        ])->assertUnprocessable();

        $role = CmsRole::query()->where('key', 'booking_agent')->firstOrFail();
        $this->assertDatabaseHas('cms_role_user', ['user_id' => $staff->id, 'role_id' => $role->id]);
    }

    private function withRole(string $key): User
    {
        $user = User::factory()->create();
        $role = CmsRole::query()->where('key', $key)->firstOrFail();
        $role->users()->attach($user->getKey());

        return $user;
    }
}
