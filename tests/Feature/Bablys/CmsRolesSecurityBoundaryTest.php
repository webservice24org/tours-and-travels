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

/** P2B.4C — external authorization boundaries; no production routes are introduced. */
final class CmsRolesSecurityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_guests_cannot_open_roles_screen_or_use_roles_and_staff_apis(): void
    {
        $this->get('/admin/roles')->assertRedirect('/login');
        $this->getJson('/admin/internal/cms-roles')->assertUnauthorized();
        $this->getJson('/admin/internal/cms-roles/catalog')->assertUnauthorized();
        $this->postJson('/admin/internal/cms-roles', $this->rolePayload('custom_guest'))
            ->assertUnauthorized();
        $this->getJson('/admin/internal/staff-users')->assertUnauthorized();
        $this->assertDatabaseMissing('cms_roles', ['key' => 'custom_guest']);
    }

    public function test_customers_and_regular_staff_cannot_view_or_mutate_role_management(): void
    {
        $owner = $this->withRole('super_admin');
        $created = $this->actingAs($owner)->postJson(
            '/admin/internal/cms-roles', $this->rolePayload('custom_locked')
        )->assertCreated();
        $id = (int) $created->json('data.id');

        foreach ([User::factory()->create(), $this->withRole('booking_agent')] as $actor) {
            $this->actingAs($actor)->get('/admin/roles')->assertForbidden();
            $this->actingAs($actor)->getJson('/admin/internal/cms-roles')->assertForbidden();
            $this->actingAs($actor)->getJson('/admin/internal/cms-roles/catalog')->assertForbidden();
            $this->actingAs($actor)->postJson('/admin/internal/cms-roles',
                $this->rolePayload('custom_escalate'))->assertForbidden();
            $this->actingAs($actor)->putJson('/admin/internal/cms-roles/'.$id.'/permissions', [
                'permission_keys' => ['settings.manage'],
            ])->assertForbidden();
            $this->actingAs($actor)->deleteJson('/admin/internal/cms-roles/'.$id)
                ->assertForbidden();
        }

        $this->assertDatabaseHas('cms_roles', ['id' => $id, 'name' => 'Restricted Test Role']);
        $this->assertDatabaseMissing('cms_roles', ['key' => 'custom_escalate']);
    }

    public function test_forged_roles_manage_grant_does_not_make_non_owner_a_role_administrator(): void
    {
        $editor = $this->withRole('content_editor');
        $editorRole = CmsRole::query()->where('key', 'content_editor')->firstOrFail();
        $restricted = CmsPermission::query()->where('key', 'roles.manage')->firstOrFail();
        $editorRole->permissions()->syncWithoutDetaching([$restricted->getKey()]);

        // Request-time gate sees this tampered DB grant; owner identity is still enforced in services.
        self::assertTrue(Gate::forUser($editor)->allows('roles.manage'));
        $this->actingAs($editor)->get('/admin/roles')->assertForbidden();
        $this->actingAs($editor)->getJson('/admin/internal/cms-roles')->assertForbidden();
        $this->actingAs($editor)->getJson('/admin/internal/staff-users')->assertForbidden();
        $this->actingAs($editor)->postJson('/admin/internal/cms-roles',
            $this->rolePayload('custom_escalate'))->assertForbidden();
        $this->assertDatabaseMissing('cms_roles', ['key' => 'custom_escalate']);
    }

    public function test_system_role_is_immutable_even_if_is_system_flag_was_tampered(): void
    {
        $owner = $this->withRole('super_admin');
        $role = CmsRole::query()->where('key', 'content_editor')->firstOrFail();
        $initialName = (string) $role->name;
        $initialCount = $role->permissions()->count();

        $role->forceFill(['is_system' => false])->save();
        $this->actingAs($owner)->getJson('/admin/internal/cms-roles/'.$role->getKey())
            ->assertOk()->assertJsonPath('data.can_edit', false);
        $this->actingAs($owner)->putJson('/admin/internal/cms-roles/'.$role->getKey(), [
            'name' => 'Compromised Role', 'description' => null,
        ])->assertUnprocessable();
        $this->actingAs($owner)->putJson('/admin/internal/cms-roles/'.$role->getKey().'/permissions', [
            'permission_keys' => [],
        ])->assertUnprocessable();
        $this->actingAs($owner)->deleteJson('/admin/internal/cms-roles/'.$role->getKey())
            ->assertUnprocessable();

        self::assertSame($initialName, (string) $role->refresh()->name);
        self::assertSame($initialCount, $role->permissions()->count());
    }

    public function test_custom_role_creation_cannot_set_system_flag_or_owner_only_permissions(): void
    {
        $owner = $this->withRole('super_admin');
        $systemPayload = $this->rolePayload('custom_false_system');
        $systemPayload['is_system'] = true;
        $this->actingAs($owner)->postJson('/admin/internal/cms-roles', $systemPayload)
            ->assertUnprocessable()->assertJsonValidationErrors('is_system');

        foreach (['roles.manage', 'settings.manage', 'audit.view'] as $permission) {
            $attempt = $this->rolePayload('custom_unsafe');
            $attempt['permission_keys'] = [$permission];
            $this->actingAs($owner)->postJson('/admin/internal/cms-roles', $attempt)
                ->assertUnprocessable();
        }
        $this->assertDatabaseMissing('cms_roles', ['key' => 'custom_false_system']);
        $this->assertDatabaseMissing('cms_roles', ['key' => 'custom_unsafe']);
    }

    public function test_permission_catalog_flags_owner_only_grants_as_not_assignable(): void
    {
        $owner = $this->withRole('super_admin');
        $catalog = $this->actingAs($owner)->getJson('/admin/internal/cms-roles/catalog')
            ->assertOk()->json('data');
        self::assertIsArray($catalog);
        $byKey = [];
        foreach ($catalog as $item) {
            $byKey[(string) $item['key']] = (bool) $item['assignable'];
        }
        self::assertCount(28, $byKey);
        foreach (['roles.manage', 'settings.manage', 'audit.view'] as $protected) {
            self::assertFalse($byKey[$protected]);
        }
        self::assertTrue($byKey['tours.view']);
        self::assertTrue($byKey['reviews.moderate']);
    }

    public function test_owner_roles_page_and_role_api_expose_no_user_credentials(): void
    {
        $owner = $this->withRole('super_admin');
        $this->actingAs($owner)->get('/admin/roles')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/roles/index'));
        $this->actingAs($owner)->getJson('/admin/internal/cms-roles')
            ->assertOk()
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token');
    }

    /** @return array{key:string,name:string,description:?string,permission_keys:list<string>} */
    private function rolePayload(string $key): array
    {
        return [
            'key' => $key,
            'name' => 'Restricted Test Role',
            'description' => null,
            'permission_keys' => ['tours.view'],
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
