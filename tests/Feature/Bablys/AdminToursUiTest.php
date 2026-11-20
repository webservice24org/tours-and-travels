<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsPermission;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** P3D.2: Tour UI security, native sidebar integration and shared feedback contract. */
final class AdminToursUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_guest_must_log_in_to_view_tour_ui(): void
    {
        $this->get('/admin/tours')->assertRedirect(route('login'));
    }

    public function test_registered_customer_is_forbidden_from_tour_ui(): void
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->get('/admin/tours')->assertForbidden();
    }

    public function test_staff_without_tour_view_permission_is_forbidden(): void
    {
        $this->actingAs($this->withRole('review_moderator'))
            ->get('/admin/tours')->assertForbidden();
    }

    public function test_content_editor_receives_page_with_draft_capabilities_only(): void
    {
        $this->actingAs($this->withRole('content_editor'))
            ->get('/admin/tours')->assertOk()->assertInertia(
                fn (Assert $page) => $page->component('admin/tours/index')
                    ->where('can.create', true)
                    ->where('can.edit', true)
                    ->where('can.publish', false)
            );
    }

    public function test_designer_receives_read_only_tour_page(): void
    {
        $this->actingAs($this->withRole('designer'))
            ->get('/admin/tours')->assertOk()->assertInertia(
                fn (Assert $page) => $page->component('admin/tours/index')
                    ->where('can.create', false)
                    ->where('can.edit', false)
                    ->where('can.publish', false)
            );
    }

    public function test_super_admin_receives_publishing_controls(): void
    {
        $this->actingAs($this->withRole('super_admin'))
            ->get('/admin/tours')->assertOk()->assertInertia(
                fn (Assert $page) => $page->component('admin/tours/index')
                    ->where('can.create', true)
                    ->where('can.edit', true)
                    ->where('can.publish', true)
            );
    }

    public function test_unverified_and_suspended_staff_cannot_open_tour_page(): void
    {
        $editor = $this->withRole('content_editor');
        $editor->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($editor)->get('/admin/tours')->assertForbidden();

        $editor->forceFill(['email_verified_at' => now(), 'cms_staff_disabled_at' => now()])->save();
        $this->actingAs($editor)->get('/admin/tours')->assertForbidden();
    }

    public function test_tour_page_is_get_only_with_auth_and_permission_middleware(): void
    {
        $route = Route::getRoutes()->getByName('admin.tours.index');
        self::assertNotNull($route);
        self::assertSame('admin/tours', $route->uri());
        self::assertSame(['GET', 'HEAD'], $route->methods());
        $middleware = implode('|', $route->gatherMiddleware());
        self::assertStringContainsString('auth', $middleware);
        self::assertStringContainsString('EnsureCmsStaff', $middleware);
        self::assertStringContainsString('tours.view', $middleware);
    }

    public function test_permission_filtered_navigation_includes_tours_for_editor(): void
    {
        $editor = $this->withRole('content_editor');
        $this->actingAs($editor)->getJson('/admin/internal/navigation')
            ->assertOk()
            ->assertJsonFragment(['href' => '/admin/tours']);
    }

    public function test_revoking_tour_view_permission_hides_link_and_denies_ui(): void
    {
        $editor = $this->withRole('content_editor');
        $role = CmsRole::query()->where('key', 'content_editor')->firstOrFail();
        $permission = CmsPermission::query()->where('key', 'tours.view')->firstOrFail();
        $role->permissions()->detach($permission->getKey());

        $this->actingAs($editor)->getJson('/admin/internal/navigation')
            ->assertOk()->assertJsonMissing(['href' => '/admin/tours']);
        $this->actingAs($editor)->get('/admin/tours')->assertForbidden();
    }

    public function test_tour_link_stays_visible_when_only_taxonomy_permission_is_revoked(): void
    {
        $editor = $this->withRole('content_editor');
        $role = CmsRole::query()->where('key', 'content_editor')->firstOrFail();
        $permission = CmsPermission::query()->where('key', 'taxonomy.manage')->firstOrFail();
        $role->permissions()->detach($permission->getKey());

        $this->actingAs($editor)->getJson('/admin/internal/navigation')
            ->assertOk()
            ->assertJsonFragment(['href' => '/admin/tours'])
            ->assertJsonMissing(['href' => '/admin/destinations']);
    }

    public function test_react_page_uses_shared_layout_feedback_and_backend_without_second_sidebar(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/pages/admin/tours/index.tsx'));
        self::assertStringContainsString('<BablysAdminLayout>', $source);
        self::assertStringContainsString('confirmAction(', $source);
        self::assertStringContainsString('toast.success(', $source);
        self::assertStringContainsString('toast.error(', $source);
        self::assertStringContainsString("const API = '/admin/internal/tours'", $source);
        self::assertStringNotContainsString('window.confirm(', $source);
        self::assertStringNotContainsString('<aside', $source);
        self::assertStringNotContainsString('<CmsSidebarMenu', $source);
    }

    private function withRole(string $roleKey): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        CmsRole::query()->where('key', $roleKey)->firstOrFail()->users()->attach($user->getKey());

        return $user;
    }
}
