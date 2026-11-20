<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Http\Middleware\EnsureCmsStaff;
use App\Models\Cms\CmsPermission;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class CmsAdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_guest_must_authenticate_to_read_navigation(): void
    {
        $this->get('/admin/internal/navigation')->assertRedirect(route('login'));
    }

    public function test_customer_cannot_read_navigation(): void
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->getJson('/admin/internal/navigation')->assertForbidden();
    }

    public function test_content_editor_sees_both_taxonomy_pages_but_no_owner_pages(): void
    {
        $editor = $this->withRole('content_editor');
        $response = $this->actingAs($editor)->getJson('/admin/internal/navigation');

        $response->assertOk()
            ->assertJsonFragment(['href' => '/admin/tour-categories'])
            ->assertJsonFragment(['href' => '/admin/destinations'])
            ->assertJsonMissing(['href' => '/admin/staff-users'])
            ->assertJsonMissing(['href' => '/admin/roles'])
            ->assertJsonMissing(['href' => '/admin/audit-logs']);
    }

    public function test_non_taxonomy_staff_gets_no_taxonomy_navigation(): void
    {
        $reviewer = $this->withRole('review_moderator');
        $this->actingAs($reviewer)->getJson('/admin/internal/navigation')
            ->assertOk()
            ->assertJsonFragment(['href' => '/admin'])
            ->assertJsonMissing(['href' => '/admin/destinations'])
            ->assertJsonMissing(['href' => '/admin/tour-categories']);
    }

    public function test_owner_sees_current_admin_modules(): void
    {
        $owner = $this->withRole('super_admin');
        $response = $this->actingAs($owner)->getJson('/admin/internal/navigation');

        $response->assertOk()
            ->assertJsonFragment(['href' => '/admin/staff-users'])
            ->assertJsonFragment(['href' => '/admin/roles'])
            ->assertJsonFragment(['href' => '/admin/audit-logs'])
            ->assertJsonFragment(['href' => '/admin/tour-categories'])
            ->assertJsonFragment(['href' => '/admin/destinations'])
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_revoking_taxonomy_permission_hides_both_links_immediately(): void
    {
        $editor = $this->withRole('content_editor');
        $this->actingAs($editor)->getJson('/admin/internal/navigation')
            ->assertJsonFragment(['href' => '/admin/tour-categories']);

        $role = CmsRole::query()->where('key', 'content_editor')->firstOrFail();
        $permission = CmsPermission::query()->where('key', 'taxonomy.manage')->firstOrFail();
        $role->permissions()->detach($permission->getKey());

        $this->actingAs($editor)->getJson('/admin/internal/navigation')
            ->assertOk()
            ->assertJsonMissing(['href' => '/admin/tour-categories'])
            ->assertJsonMissing(['href' => '/admin/destinations']);
    }

    public function test_non_owner_cannot_see_owner_links_even_if_role_grants_are_tampered_with(): void
    {
        $moderator = $this->withRole('review_moderator');
        $role = CmsRole::query()->where('key', 'review_moderator')->firstOrFail();
        $permissionIds = CmsPermission::query()
            ->whereIn('key', ['roles.manage', 'audit.view'])->pluck('id')->all();
        $role->permissions()->syncWithoutDetaching($permissionIds);

        $this->actingAs($moderator)->getJson('/admin/internal/navigation')
            ->assertOk()
            ->assertJsonMissing(['href' => '/admin/staff-users'])
            ->assertJsonMissing(['href' => '/admin/roles'])
            ->assertJsonMissing(['href' => '/admin/audit-logs']);
    }

    public function test_suspension_and_missing_verification_deny_navigation(): void
    {
        $staff = $this->withRole('content_editor');
        $staff->forceFill(['cms_staff_disabled_at' => now()])->save();
        $this->actingAs($staff)->getJson('/admin/internal/navigation')->assertForbidden();

        $staff->forceFill(['cms_staff_disabled_at' => null, 'email_verified_at' => null])->save();
        $this->actingAs($staff)->getJson('/admin/internal/navigation')->assertForbidden();
    }

    public function test_navigation_endpoint_has_no_write_route_and_remains_staff_protected(): void
    {
        $route = Route::getRoutes()->getByName('admin.navigation.show');
        self::assertNotNull($route);
        self::assertContains(EnsureCmsStaff::class, $route->gatherMiddleware());
        self::assertSame(['GET', 'HEAD'], $route->methods());

        $this->actingAs($this->withRole('super_admin'))
            ->postJson('/admin/internal/navigation')->assertStatus(405);
    }

    public function test_shared_layout_is_present_in_all_six_admin_pages(): void
    {
        foreach ([
            'resources/js/pages/admin/index.tsx',
            'resources/js/pages/admin/staff-users/index.tsx',
            'resources/js/pages/admin/roles/index.tsx',
            'resources/js/pages/admin/audit-logs/index.tsx',
            'resources/js/pages/admin/tour-categories/index.tsx',
            'resources/js/pages/admin/destinations/index.tsx',
        ] as $page) {
            $content = (string) file_get_contents(base_path($page));
            self::assertStringContainsString('<BablysAdminLayout>', $content, $page);
            self::assertStringNotContainsString('<CmsAdminNavigation />', $content, $page);
        }
    }

    private function withRole(string $key): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        CmsRole::query()->where('key', $key)->firstOrFail()->users()->attach($user->getKey());

        return $user;
    }
}
