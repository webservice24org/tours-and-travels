<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsPermission;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CmsRolesUiAndStaffAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_roles_screen_is_owner_only(): void
    {
        $this->get('/admin/roles')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/roles')->assertForbidden();
        $this->actingAs($this->withRole('content_editor'))->get('/admin/roles')->assertForbidden();
        $this->actingAs($this->withRole('super_admin'))->get('/admin/roles')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/roles/index'));
    }

    public function test_non_owner_with_accidental_roles_manage_grant_still_cannot_open_roles_screen(): void
    {
        $editor = $this->withRole('content_editor');
        $role = CmsRole::query()->where('key', 'content_editor')->firstOrFail();
        $grant = CmsPermission::query()->where('key', 'roles.manage')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$grant->getKey()]);

        $this->actingAs($editor)->get('/admin/roles')->assertForbidden();
    }

    public function test_custom_role_is_available_in_staff_ui_and_can_be_assigned_and_removed(): void
    {
        $owner = $this->withRole('super_admin');
        $created = $this->actingAs($owner)->postJson('/admin/internal/cms-roles', [
            'key' => 'custom_tour_agent', 'name' => 'Tour Agent', 'description' => 'Tour coordination',
            'permission_keys' => ['tours.view', 'bookings.view'],
        ])->assertCreated();
        $roleId = (int) $created->json('data.id');
        $this->actingAs($owner)->get('/admin/staff-users')->assertInertia(fn (Assert $page) => $page
            ->component('admin/staff-users/index')
            ->where('roleOptions.6.key', 'custom_tour_agent'));

        $staff = $this->withRole('booking_agent');
        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$staff->id.'/roles', [
            'role_keys' => ['custom_tour_agent'],
        ])->assertOk()->assertJsonPath('data.role_keys', ['custom_tour_agent']);
        self::assertTrue(Gate::forUser($staff->refresh())->allows('tours.view'));
        self::assertFalse(Gate::forUser($staff)->allows('bookings.manage'));

        $this->actingAs($owner)->deleteJson('/admin/internal/cms-roles/'.$roleId)
            ->assertUnprocessable();
        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$staff->id.'/roles', [
            'role_keys' => [],
        ])->assertOk()->assertJsonPath('data.role_keys', []);
        self::assertFalse(Gate::forUser($staff->refresh())->allows('tours.view'));
        $this->actingAs($owner)->deleteJson('/admin/internal/cms-roles/'.$roleId)->assertNoContent();
    }

    public function test_owner_can_create_pending_staff_with_a_custom_role(): void
    {
        $owner = $this->withRole('super_admin');
        $this->actingAs($owner)->postJson('/admin/internal/cms-roles', [
            'key' => 'custom_gallery_staff', 'name' => 'Gallery Staff', 'description' => null,
            'permission_keys' => ['galleries.edit'],
        ])->assertCreated();

        $this->actingAs($owner)->postJson('/admin/internal/staff-users', [
            'name' => 'New Gallery Staff', 'email' => 'new.gallery.staff@example.com',
            'role_keys' => ['custom_gallery_staff'],
        ])->assertCreated()->assertJsonPath('data.role_keys', ['custom_gallery_staff']);
        $this->assertDatabaseHas('users', ['email' => 'new.gallery.staff@example.com', 'email_verified_at' => null]);
    }

    public function test_custom_role_with_owner_only_permission_cannot_be_assigned_even_if_database_was_modified(): void
    {
        $owner = $this->withRole('super_admin');
        $created = $this->actingAs($owner)->postJson('/admin/internal/cms-roles', [
            'key' => 'custom_review_ops', 'name' => 'Review Ops', 'description' => null,
            'permission_keys' => ['reviews.moderate'],
        ])->assertCreated();
        $role = CmsRole::query()->findOrFail((int) $created->json('data.id'));
        $restricted = CmsPermission::query()->where('key', 'roles.manage')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$restricted->getKey()]);

        $staff = $this->withRole('booking_agent');
        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$staff->id.'/roles', [
            'role_keys' => ['custom_review_ops'],
        ])->assertUnprocessable()->assertJsonValidationErrors('role_keys');
        $this->assertDatabaseHas('cms_role_user', [
            'role_id' => CmsRole::query()->where('key', 'booking_agent')->firstOrFail()->id,
            'user_id' => $staff->id,
        ]);
    }

    public function test_super_admin_cannot_be_assigned_using_staff_endpoint(): void
    {
        $owner = $this->withRole('super_admin');
        $staff = $this->withRole('content_editor');
        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$staff->id.'/roles', [
            'role_keys' => ['super_admin'],
        ])->assertUnprocessable();
    }

    private function withRole(string $key): User
    {
        $user = User::factory()->create();
        $role = CmsRole::query()->where('key', $key)->firstOrFail();
        $role->users()->attach($user->getKey());
        return $user;
    }
}
