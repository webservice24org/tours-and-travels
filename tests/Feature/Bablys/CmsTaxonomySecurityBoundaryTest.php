<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsPermission;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Ensures UI, JSON endpoints and nav have the same live authorization boundary. */
final class CmsTaxonomySecurityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_guests_cannot_open_taxonomy_pages_or_json_apis(): void
    {
        $this->get('/admin/tour-categories')->assertRedirect(route('login'));
        $this->get('/admin/destinations')->assertRedirect(route('login'));
        $this->getJson('/admin/internal/tour-categories')->assertUnauthorized();
        $this->getJson('/admin/internal/destinations')->assertUnauthorized();
    }

    public function test_regular_customers_are_forbidden_across_both_taxonomy_surfaces(): void
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
        $this->get('/admin/tour-categories')->assertForbidden();
        $this->get('/admin/destinations')->assertForbidden();
        $this->getJson('/admin/internal/tour-categories')->assertForbidden();
        $this->getJson('/admin/internal/destinations')->assertForbidden();
    }

    public function test_unrelated_staff_cannot_write_either_taxonomy_or_see_its_nav_links(): void
    {
        $this->actingAs($this->staff('review_moderator'));
        $this->postJson('/admin/internal/tour-categories', $this->category())->assertForbidden();
        $this->postJson('/admin/internal/destinations', $this->destination())->assertForbidden();
        $this->getJson('/admin/internal/navigation')->assertOk()
            ->assertJsonMissing(['href' => '/admin/tour-categories'])
            ->assertJsonMissing(['href' => '/admin/destinations']);
        self::assertSame(0, DB::table('cms_audit_events')->count());
    }

    public function test_revoking_taxonomy_permission_takes_effect_across_pages_api_and_nav_without_relogin(): void
    {
        $editor = $this->staff('content_editor');
        $this->actingAs($editor)->getJson('/admin/internal/navigation')
            ->assertJsonFragment(['href' => '/admin/destinations']);

        $role = CmsRole::query()->where('key', 'content_editor')->firstOrFail();
        $permission = CmsPermission::query()->where('key', 'taxonomy.manage')->firstOrFail();
        $role->permissions()->detach($permission->getKey());

        $this->actingAs($editor)->get('/admin/tour-categories')->assertForbidden();
        $this->actingAs($editor)->get('/admin/destinations')->assertForbidden();
        $this->actingAs($editor)->postJson('/admin/internal/tour-categories', $this->category())->assertForbidden();
        $this->actingAs($editor)->postJson('/admin/internal/destinations', $this->destination())->assertForbidden();
        $this->actingAs($editor)->getJson('/admin/internal/navigation')->assertOk()
            ->assertJsonMissing(['href' => '/admin/tour-categories'])
            ->assertJsonMissing(['href' => '/admin/destinations']);
    }

    public function test_suspending_staff_immediately_denies_both_page_and_api_surfaces(): void
    {
        $editor = $this->staff('content_editor');
        $this->actingAs($editor)->get('/admin/tour-categories')->assertOk();
        $editor->forceFill(['cms_staff_disabled_at' => now()])->save();
        $this->actingAs($editor)->get('/admin/tour-categories')->assertForbidden();
        $this->actingAs($editor)->get('/admin/destinations')->assertForbidden();
        $this->actingAs($editor)->getJson('/admin/internal/tour-categories')->assertForbidden();
        $this->actingAs($editor)->getJson('/admin/internal/destinations')->assertForbidden();
    }

    public function test_taxonomy_routes_remain_named_and_database_permission_protected(): void
    {
        foreach ([
            'admin.tour-categories.index',
            'admin.destinations.index',
            'admin.internal.tour-categories.index',
            'admin.internal.tour-categories.store',
            'admin.internal.destinations.index',
            'admin.internal.destinations.store',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, $name);
            $middleware = implode('|', $route->gatherMiddleware());
            self::assertStringContainsString('auth', $middleware, $name);
            self::assertStringContainsString('EnsureCmsStaff', $middleware, $name);
            self::assertStringContainsString('taxonomy.manage', $middleware, $name);
        }
    }

    /** @return array{name:string,slug:string,status:string} */
    private function category(): array
    {
        return ['name' => 'Sensitive Category', 'slug' => 'sensitive-category', 'status' => 'draft'];
    }

    /** @return array{name:string,slug:string,country_code:string,status:string} */
    private function destination(): array
    {
        return [
            'name' => 'Sensitive Destination', 'slug' => 'sensitive-destination',
            'country_code' => 'BD', 'status' => 'draft',
        ];
    }

    private function staff(string $key): User
    {
        $staff = User::factory()->create(['email_verified_at' => now()]);
        CmsRole::query()->where('key', $key)->firstOrFail()->users()->attach($staff->getKey());

        return $staff;
    }
}
