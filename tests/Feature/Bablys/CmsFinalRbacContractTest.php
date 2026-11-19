<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Access\CmsAccess;
use App\Domain\Cms\Access\RbacCatalog;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/** P2B.6A — canonical role/permission matrix and fail-closed authorization. */
final class CmsFinalRbacContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_all_28_declared_permissions_have_registered_laravel_gates(): void
    {
        $keys = array_keys(RbacCatalog::permissions());
        self::assertCount(28, $keys);
        self::assertSame(count($keys), count(array_unique($keys)));

        foreach ($keys as $key) {
            self::assertTrue(Gate::has($key), "Missing Bablys Gate: {$key}");
        }
    }

    public function test_unassigned_customer_cannot_use_any_cms_permission_or_unknown_ability(): void
    {
        $customer = User::factory()->create();
        $access = app(CmsAccess::class);

        self::assertSame([], $access->roleKeysFor($customer));
        self::assertSame([], $access->permissionKeysFor($customer));
        self::assertFalse($access->canEnterAdmin($customer));

        foreach (array_keys(RbacCatalog::permissions()) as $key) {
            self::assertFalse(Gate::forUser($customer)->allows($key), "Customer unexpectedly allowed: {$key}");
        }
        self::assertFalse($access->allows($customer, 'not_a_registered_permission'));
        self::assertFalse(Gate::forUser($customer)->allows('not_a_registered_permission'));
    }

    public function test_seven_seeded_system_roles_match_the_canonical_grant_matrix(): void
    {
        $catalog = RbacCatalog::permissions();
        self::assertCount(7, RbacCatalog::roles());
        self::assertSame(7, (int) DB::table('cms_roles')->where('is_system', true)->count());
        self::assertSame(28, (int) DB::table('cms_permissions')->where('scope', 'admin')->count());

        foreach (array_keys(RbacCatalog::roles()) as $roleKey) {
            $role = CmsRole::query()->where('key', $roleKey)->firstOrFail();
            self::assertTrue((bool) $role->is_system);

            $expected = [];
            foreach ($catalog as $permission => $definition) {
                if (in_array($roleKey, $definition['roles'], true)) {
                    $expected[] = $permission;
                }
            }
            sort($expected, SORT_STRING);

            $actual = $role->permissions()->pluck('key')->map(static fn (mixed $value): string => (string) $value)->all();
            sort($actual, SORT_STRING);
            self::assertSame($expected, $actual, "Seed grants differ for role {$roleKey}");
        }
    }

    public function test_super_admin_requires_explicit_assignment_but_then_has_catalog_grants(): void
    {
        $owner = User::factory()->create();
        self::assertFalse(app(CmsAccess::class)->canEnterAdmin($owner));

        $ownerRole = CmsRole::query()->where('key', 'super_admin')->firstOrFail();
        $ownerRole->users()->attach($owner->getKey());

        foreach (array_keys(RbacCatalog::permissions()) as $key) {
            self::assertTrue(Gate::forUser($owner)->allows($key), "Explicit owner missing: {$key}");
        }
    }

    public function test_reseeding_preserves_deliberately_revoked_system_role_permission(): void
    {
        $administrator = CmsRole::query()->where('key', 'administrator')->firstOrFail();
        $permissionId = (int) DB::table('cms_permissions')->where('key', 'tours.publish')->value('id');
        self::assertGreaterThan(0, $permissionId);
        $administrator->permissions()->detach($permissionId);

        $this->seed(BablysCmsRbacSeeder::class);

        $this->assertDatabaseMissing('cms_permission_role', [
            'role_id' => $administrator->getKey(),
            'permission_id' => $permissionId,
        ]);
        self::assertSame(7, (int) DB::table('cms_roles')->where('is_system', true)->count());
        self::assertSame(28, (int) DB::table('cms_permissions')->count());
    }

    public function test_permission_scope_change_takes_effect_without_reauthenticating(): void
    {
        $editor = User::factory()->create();
        $role = CmsRole::query()->where('key', 'content_editor')->firstOrFail();
        $role->users()->attach($editor->getKey());

        self::assertTrue(Gate::forUser($editor)->allows('tours.view'));
        DB::table('cms_permissions')->where('key', 'tours.view')->update(['scope' => 'public']);
        self::assertFalse(Gate::forUser($editor)->allows('tours.view'));
        DB::table('cms_permissions')->where('key', 'tours.view')->update(['scope' => 'admin']);
        self::assertTrue(Gate::forUser($editor)->allows('tours.view'));
    }
}
