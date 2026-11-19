<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsPermission;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class CmsCustomRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_guests_customers_and_regular_staff_cannot_use_role_management(): void
    {
        $this->getJson('/admin/internal/cms-roles')->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->getJson('/admin/internal/cms-roles')->assertForbidden();

        $editor = $this->withRole('content_editor');
        $this->actingAs($editor)->postJson('/admin/internal/cms-roles', $this->payload())
            ->assertForbidden();
        $this->assertDatabaseMissing('cms_roles', ['key' => 'custom_trip_manager']);
    }

    public function test_a_non_owner_with_roles_manage_grant_still_cannot_manage_custom_roles(): void
    {
        $notOwner = $this->withRole('content_editor');
        $role = CmsRole::query()->where('key', 'content_editor')->firstOrFail();
        $grant = CmsPermission::query()->where('key', 'roles.manage')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$grant->getKey()]);

        $this->actingAs($notOwner)
            ->postJson('/admin/internal/cms-roles', $this->payload())
            ->assertForbidden();
        $this->actingAs($notOwner)
            ->getJson('/admin/internal/cms-roles')->assertForbidden();
    }

    public function test_owner_can_list_roles_and_approved_permission_catalog_without_secrets(): void
    {
        $owner = $this->withRole('super_admin');
        $this->actingAs($owner)->getJson('/admin/internal/cms-roles?type=system')
            ->assertOk()
            ->assertJsonPath('meta.total', 7)
            ->assertJsonCount(7, 'data')
            ->assertJsonMissingPath('data.0.password');
        $this->actingAs($owner)->getJson('/admin/internal/cms-roles/catalog')
            ->assertOk()
            ->assertJsonCount(28, 'data');
    }

    public function test_owner_creates_edits_and_updates_custom_role_permissions(): void
    {
        $owner = $this->withRole('super_admin');
        $created = $this->actingAs($owner)
            ->postJson('/admin/internal/cms-roles', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.key', 'custom_trip_manager')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.permission_keys', ['tours.edit', 'tours.view']);
        $id = (int) $created->json('data.id');

        $this->actingAs($owner)->putJson('/admin/internal/cms-roles/'.$id, [
            'name' => 'Tour Operations Manager',
            'description' => 'Can coordinate tour content.',
        ])->assertOk()->assertJsonPath('data.name', 'Tour Operations Manager');

        $this->actingAs($owner)->putJson('/admin/internal/cms-roles/'.$id.'/permissions', [
            'permission_keys' => ['tours.view'],
        ])->assertOk()->assertJsonPath('data.permission_keys', ['tours.view']);

        $this->actingAs($owner)->getJson('/admin/internal/cms-roles/'.$id)
            ->assertOk()->assertJsonPath('data.description', 'Can coordinate tour content.');
        $this->assertDatabaseHas('cms_roles', ['id' => $id, 'key' => 'custom_trip_manager', 'is_system' => 0]);
    }

    public function test_owner_only_permissions_unknown_keys_and_duplicate_permissions_are_rejected(): void
    {
        $owner = $this->withRole('super_admin');
        foreach (['roles.manage', 'settings.manage', 'audit.view', 'unknown.permission'] as $invalid) {
            $data = $this->payload();
            $data['permission_keys'] = [$invalid];
            $this->actingAs($owner)->postJson('/admin/internal/cms-roles', $data)
                ->assertUnprocessable();
        }
        $data = $this->payload();
        $data['permission_keys'] = ['tours.view', 'tours.view'];
        $this->actingAs($owner)->postJson('/admin/internal/cms-roles', $data)
            ->assertUnprocessable()->assertJsonValidationErrors('permission_keys.1');
        $this->assertDatabaseMissing('cms_roles', ['key' => 'custom_trip_manager']);
    }

    public function test_custom_role_key_cannot_impersonate_system_role_and_is_immutable(): void
    {
        $owner = $this->withRole('super_admin');
        $data = $this->payload();
        $data['key'] = 'super_admin';
        $this->actingAs($owner)->postJson('/admin/internal/cms-roles', $data)->assertUnprocessable();

        $created = $this->actingAs($owner)->postJson('/admin/internal/cms-roles', $this->payload())
            ->assertCreated();
        $id = (int) $created->json('data.id');
        $this->actingAs($owner)->putJson('/admin/internal/cms-roles/'.$id, [
            'name' => 'Bad', 'key' => 'super_admin',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('cms_roles', ['id' => $id, 'key' => 'custom_trip_manager']);
    }

    public function test_system_roles_are_immutable_even_to_owner(): void
    {
        $owner = $this->withRole('super_admin');
        $system = CmsRole::query()->where('key', 'content_editor')->firstOrFail();
        $before = $system->permissions()->count();

        $this->actingAs($owner)->putJson('/admin/internal/cms-roles/'.$system->id, [
            'name' => 'Rewritten role', 'description' => null,
        ])->assertUnprocessable();
        $this->actingAs($owner)->putJson('/admin/internal/cms-roles/'.$system->id.'/permissions', [
            'permission_keys' => [],
        ])->assertUnprocessable();
        $this->actingAs($owner)->deleteJson('/admin/internal/cms-roles/'.$system->id)
            ->assertUnprocessable();

        $this->assertDatabaseHas('cms_roles', ['id' => $system->id, 'name' => $system->name]);
        self::assertSame($before, $system->permissions()->count());
    }

    public function test_empty_permission_list_revokes_live_access_without_new_login(): void
    {
        $owner = $this->withRole('super_admin');
        $result = $this->actingAs($owner)->postJson('/admin/internal/cms-roles', $this->payload())
            ->assertCreated();
        $id = (int) $result->json('data.id');
        $role = CmsRole::query()->findOrFail($id);
        $staff = User::factory()->create();
        $role->users()->attach($staff->getKey());

        self::assertTrue(Gate::forUser($staff)->allows('tours.edit'));
        $this->actingAs($owner)->putJson('/admin/internal/cms-roles/'.$id.'/permissions', [
            'permission_keys' => [],
        ])->assertOk()->assertJsonPath('data.permission_keys', []);
        self::assertFalse(Gate::forUser($staff)->allows('tours.edit'));
        $this->actingAs($staff)->get('/admin')->assertForbidden();
    }

    public function test_custom_role_with_users_cannot_be_deleted_until_reassigned(): void
    {
        $owner = $this->withRole('super_admin');
        $result = $this->actingAs($owner)->postJson('/admin/internal/cms-roles', $this->payload())
            ->assertCreated();
        $id = (int) $result->json('data.id');
        $role = CmsRole::query()->findOrFail($id);
        $staff = User::factory()->create();
        $role->users()->attach($staff->getKey());

        $this->actingAs($owner)->deleteJson('/admin/internal/cms-roles/'.$id)
            ->assertUnprocessable();
        $this->assertDatabaseHas('cms_roles', ['id' => $id]);
        $role->users()->detach($staff->getKey());
        $this->actingAs($owner)->deleteJson('/admin/internal/cms-roles/'.$id)
            ->assertNoContent();
        $this->assertDatabaseMissing('cms_roles', ['id' => $id]);
    }

    public function test_duplicate_key_and_missing_permission_keys_fail_validation(): void
    {
        $owner = $this->withRole('super_admin');
        $this->actingAs($owner)->postJson('/admin/internal/cms-roles', $this->payload())
            ->assertCreated();
        $this->actingAs($owner)->postJson('/admin/internal/cms-roles', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('key');
        $noList = $this->payload();
        unset($noList['permission_keys']);
        $noList['key'] = 'custom_extra_role';
        $this->actingAs($owner)->postJson('/admin/internal/cms-roles', $noList)
            ->assertUnprocessable()->assertJsonValidationErrors('permission_keys');
    }

    /** @return array{key:string,name:string,description:string,permission_keys:list<string>} */
    private function payload(): array
    {
        return [
            'key' => 'custom_trip_manager',
            'name' => 'Trip Manager',
            'description' => 'Coordinates tour content',
            'permission_keys' => ['tours.view', 'tours.edit'],
        ];
    }

    private function withRole(string $key): User
    {
        $user = User::factory()->create();
        $role = CmsRole::query()->where('key', $key)->firstOrFail();
        $role->users()->attach($user->getKey());

        return $user;
    }
}
