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

/** P2B.4C — end-to-end owner UI/API -> DB -> staff Gate integration. */
final class CmsRolesWorkflowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_custom_role_moves_from_api_to_staff_ui_to_live_authorization(): void
    {
        $owner = $this->withRole('super_admin');
        $staff = $this->withRole('booking_agent');

        $created = $this->actingAs($owner)->postJson('/admin/internal/cms-roles', [
            'key' => 'custom_gallery_team',
            'name' => 'Gallery Team',
            'description' => 'Maintains albums',
            'permission_keys' => ['galleries.edit', 'media.upload'],
        ])->assertCreated()->assertJsonPath('data.is_system', false);
        $id = (int) $created->json('data.id');

        $this->actingAs($owner)->get('/admin/staff-users')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('admin/staff-users/index')
                ->where('roleOptions.6.key', 'custom_gallery_team')
        );

        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$staff->getKey().'/roles', [
            'role_keys' => ['custom_gallery_team'],
        ])->assertOk()->assertJsonPath('data.role_keys', ['custom_gallery_team']);

        self::assertTrue(Gate::forUser($staff)->allows('galleries.edit'));
        self::assertTrue(Gate::forUser($staff)->allows('media.upload'));
        self::assertFalse(Gate::forUser($staff)->allows('bookings.manage'));
        $this->assertDatabaseHas('cms_role_user', ['role_id' => $id, 'user_id' => $staff->getKey()]);
    }

    public function test_assigned_custom_role_cannot_be_deleted_until_staff_role_is_removed(): void
    {
        $owner = $this->withRole('super_admin');
        $staff = $this->withRole('booking_agent');
        $role = $this->createRole($owner, 'custom_delete_guard', ['bookings.view']);

        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$staff->getKey().'/roles', [
            'role_keys' => ['custom_delete_guard'],
        ])->assertOk();
        $this->actingAs($owner)->deleteJson('/admin/internal/cms-roles/'.$role->getKey())
            ->assertUnprocessable();
        $this->assertDatabaseHas('cms_roles', ['id' => $role->getKey()]);

        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$staff->getKey().'/roles', [
            'role_keys' => [],
        ])->assertOk();
        $this->actingAs($owner)->deleteJson('/admin/internal/cms-roles/'.$role->getKey())
            ->assertNoContent();
        $this->assertDatabaseMissing('cms_roles', ['id' => $role->getKey()]);
        $this->assertDatabaseMissing('cms_role_user', ['user_id' => $staff->getKey()]);
    }

    public function test_tampered_custom_role_with_owner_only_grant_cannot_be_assigned_to_staff(): void
    {
        $owner = $this->withRole('super_admin');
        $staff = $this->withRole('booking_agent');
        $role = $this->createRole($owner, 'custom_tampered', ['reviews.moderate']);
        $restricted = CmsPermission::query()->where('key', 'settings.manage')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$restricted->getKey()]);

        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$staff->getKey().'/roles', [
            'role_keys' => ['custom_tampered'],
        ])->assertUnprocessable()->assertJsonValidationErrors('role_keys');

        $original = CmsRole::query()->where('key', 'booking_agent')->firstOrFail();
        $this->assertDatabaseHas('cms_role_user', [
            'role_id' => $original->getKey(), 'user_id' => $staff->getKey(),
        ]);
        $this->assertDatabaseMissing('cms_role_user', [
            'role_id' => $role->getKey(), 'user_id' => $staff->getKey(),
        ]);
    }

    public function test_malformed_role_and_permission_requests_are_rejected_without_partial_writes(): void
    {
        $owner = $this->withRole('super_admin');
        $role = $this->createRole($owner, 'custom_validation', ['tours.view']);
        $staff = $this->withRole('content_editor');

        $this->actingAs($owner)->putJson('/admin/internal/cms-roles/'.$role->getKey().'/permissions', [
            'permission_keys' => ['tours.edit', 'tours.edit'],
        ])->assertUnprocessable();
        $this->actingAs($owner)->putJson('/admin/internal/cms-roles/'.$role->getKey().'/permissions', [
            'permission_keys' => ['unknown.key'],
        ])->assertUnprocessable();
        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$staff->getKey().'/roles', [
            'role_keys' => ['custom_validation', 'custom_validation'],
        ])->assertUnprocessable();
        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$staff->getKey().'/roles', [
            'role_keys' => ['super_admin'],
        ])->assertUnprocessable();

        $this->actingAs($owner)->getJson('/admin/internal/cms-roles/'.$role->getKey())
            ->assertOk()->assertJsonPath('data.permission_keys', ['tours.view']);
        $original = CmsRole::query()->where('key', 'content_editor')->firstOrFail();
        $this->assertDatabaseHas('cms_role_user', [
            'user_id' => $staff->getKey(), 'role_id' => $original->getKey(),
        ]);
    }

    public function test_role_query_filters_show_only_custom_roles_and_hide_system_roles(): void
    {
        $owner = $this->withRole('super_admin');
        $this->createRole($owner, 'custom_ops_search', ['tours.view']);
        $list = $this->actingAs($owner)->getJson(
            '/admin/internal/cms-roles?type=custom&search=ops_search&per_page=10'
        )->assertOk()->assertJsonPath('meta.total', 1);

        $list->assertJsonCount(1, 'data')->assertJsonPath('data.0.key', 'custom_ops_search')
            ->assertJsonPath('data.0.can_edit', true);
        $this->actingAs($owner)->getJson('/admin/internal/cms-roles?type=system')
            ->assertOk()->assertJsonPath('meta.total', 7);
    }

    public function test_public_home_remains_untouched_by_role_management(): void
    {
        $this->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('public/home'));
    }

    /** @param list<string> $permissions */
    private function createRole(User $owner, string $key, array $permissions): CmsRole
    {
        $result = $this->actingAs($owner)->postJson('/admin/internal/cms-roles', [
            'key' => $key,
            'name' => 'QA Custom Role',
            'description' => 'Tests privilege safety',
            'permission_keys' => $permissions,
        ])->assertCreated();
        return CmsRole::query()->findOrFail((int) $result->json('data.id'));
    }

    private function withRole(string $key): User
    {
        $user = User::factory()->create();
        $role = CmsRole::query()->where('key', $key)->firstOrFail();
        $role->users()->attach($user->getKey());
        return $user;
    }
}
