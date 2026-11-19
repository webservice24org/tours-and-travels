<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsPermission;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class RbacFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_schema_is_present_and_uses_existing_users(): void
    {
        foreach (['cms_roles', 'cms_permissions', 'cms_role_user', 'cms_permission_role'] as $table) {
            self::assertTrue(Schema::hasTable($table), "Missing RBAC table: {$table}");
        }
        self::assertTrue(Schema::hasTable('users'));
        self::assertTrue(Schema::hasColumn('cms_roles', 'is_system'));
        self::assertTrue(Schema::hasColumn('cms_permissions', 'scope'));
    }

    public function test_seed_is_idempotent_and_does_not_assign_staff_roles_to_users(): void
    {
        $user = User::factory()->create();

        $this->seed(BablysCmsRbacSeeder::class);
        $this->seed(BablysCmsRbacSeeder::class);

        $this->assertDatabaseCount('cms_roles', 7);
        $this->assertDatabaseCount('cms_permissions', 28);
        $this->assertDatabaseCount('cms_role_user', 0);
        self::assertNotNull(User::query()->find($user->getKey()));

        $admin = CmsRole::query()->where('key', 'administrator')->firstOrFail();
        $super = CmsRole::query()->where('key', 'super_admin')->firstOrFail();
        self::assertSame(25, $admin->permissions()->count());
        self::assertSame(28, $super->permissions()->count());
    }

    public function test_seeder_preserves_manually_revoked_grants_and_custom_role(): void
    {
        $this->seed(BablysCmsRbacSeeder::class);

        $admin = CmsRole::query()->where('key', 'administrator')->firstOrFail();
        $permission = CmsPermission::query()->where('key', 'tours.publish')->firstOrFail();
        $admin->permissions()->detach($permission->getKey());

        $custom = CmsRole::query()->create([
            'key' => 'operations_assistant',
            'name' => 'Operations Assistant',
            'is_system' => false,
        ]);
        $custom->permissions()->attach($permission->getKey());

        $this->seed(BablysCmsRbacSeeder::class);

        self::assertSame(24, $admin->permissions()->count());
        self::assertSame(1, $custom->permissions()->count());
        $this->assertDatabaseCount('cms_roles', 8);
    }

    public function test_user_role_assignment_is_explicit_and_relations_work(): void
    {
        $this->seed(BablysCmsRbacSeeder::class);

        $user = User::factory()->create();
        $role = CmsRole::query()->where('key', 'booking_agent')->firstOrFail();
        $role->users()->attach($user->getKey());

        $this->assertDatabaseHas('cms_role_user', [
            'user_id' => $user->getKey(),
            'role_id' => $role->getKey(),
        ]);
        self::assertSame(1, $role->users()->count());
        self::assertTrue($role->permissions()->where('key', 'bookings.view')->exists());
        $this->assertDatabaseCount('cms_role_user', 1);
    }
}
