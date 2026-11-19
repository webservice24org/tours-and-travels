<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsPermission;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AdminStaffManagementUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_guest_is_redirected_and_customer_is_forbidden(): void
    {
        $this->get('/admin/staff-users')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())
            ->get('/admin/staff-users')->assertForbidden();
    }

    public function test_regular_staff_cannot_open_management_screen(): void
    {
        $editor = $this->withRole('content_editor');
        $this->actingAs($editor)->get('/admin/staff-users')->assertForbidden();
    }

    public function test_non_owner_with_explicit_roles_manage_grant_is_still_denied(): void
    {
        $administrator = $this->withRole('administrator');
        $role = CmsRole::query()->where('key', 'administrator')->firstOrFail();
        $permission = CmsPermission::query()->where('key', 'roles.manage')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->getKey()]);

        $this->actingAs($administrator)->get('/admin/staff-users')->assertForbidden();
    }

    public function test_super_admin_renders_responsive_inertia_management_page_with_only_assignable_roles(): void
    {
        $owner = $this->withRole('super_admin');

        $this->actingAs($owner)->get('/admin/staff-users')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/staff-users/index')
                ->where('viewerId', $owner->getKey())
                ->has('roleOptions', 6)
                ->where('roleOptions.0.key', 'administrator'));
    }

    private function withRole(string $roleKey): User
    {
        $user = User::factory()->create();
        $role = CmsRole::query()->where('key', $roleKey)->firstOrFail();
        $role->users()->attach($user->getKey());

        return $user;
    }
}
