<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Settings\BablysSiteSettings;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/** P4A.5 WordPress-style admin workspace and native settings submenu. */
final class GlobalSettingsWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $env = (string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? ''));
        $driver = (string) (getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? ''));
        $database = (string) (getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? ''));
        if ($env !== 'testing' || $driver !== 'mysql'
            || preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*_test\z/D', $database) !== 1
            || str_ends_with($database, 'race_test')) {
            throw new \RuntimeException('P4A.5 requires APP_ENV=testing and a dedicated non-race MySQL *_test database.');
        }
        parent::setUp();
        // HTTP behaviour tests cannot share middleware throttle buckets between independent DB refreshes.
        // Production route registrations are verified separately, throttling stays active outside tests.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(BablysCmsRbacSeeder::class);
    }

    private function staff(string $role = 'super_admin'): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        CmsRole::query()->where('key', $role)->firstOrFail()->users()->attach($user->getKey());
        return $user;
    }

    /** @return array<string, mixed> */
    private function settingsInput(int $version = 0, string $brand = 'Updated Bablys'): array
    {
        $draft = app(BablysSiteSettings::class)->defaults();
        $draft['branding']['site_name'] = $brand;
        return ['expected_version' => $version, ...$draft];
    }

    /** @return array<string, mixed> */
    private function item(string $label = 'Tours', string $url = '/tours', ?string $parent = null): array
    {
        return ['id' => (string) Str::uuid(), 'label' => $label, 'url' => $url, 'kind' => 'internal', 'parent_id' => $parent, 'new_tab' => false];
    }

    public function test_guests_customers_and_unprivileged_staff_cannot_access_or_mutate_workspaces(): void
    {
        $this->get('/admin/global-settings')->assertRedirect(route('login'));
        $this->get('/admin/global-settings/header')->assertRedirect(route('login'));
        $this->post('/admin/global-settings/header/menus', ['menu_key' => 'main-header'])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
        $this->get('/admin/global-settings/footer')->assertForbidden();
        $this->put('/admin/global-settings/header/settings', $this->settingsInput())->assertForbidden();
        $this->actingAs($this->staff('content_editor'));
        $this->get('/admin/global-settings')->assertForbidden();
        $this->post('/admin/global-settings/footer/menus', ['menu_key' => 'footer-links'])->assertForbidden();
        self::assertSame(0, DB::table('bablys_navigation_menus')->count());
    }

    public function test_editor_sections_are_private_and_use_existing_admin_layout(): void
    {
        $this->actingAs($this->staff());
        foreach (['', '/header', '/footer'] as $part) {
            $this->get('/admin/global-settings'.$part)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
                ->assertInertia(fn ($page) => $page->component('admin/global-settings/workspace'));
        }
        $this->get('/admin/global-settings/header')->assertInertia(fn ($page) => $page
            ->where('section', 'header')
            ->where('menus.0.key', 'main-header')
            ->where('menus.0.menu', null));
        $this->get('/admin/global-settings/footer')->assertInertia(fn ($page) => $page
            ->where('section', 'footer')
            ->where('menus.0.key', 'footer-domestic')
            ->where('menus.1.key', 'footer-global')
            ->where('menus.2.key', 'footer-links'));
    }

    public function test_header_settings_save_is_a_private_draft_until_explicit_publication(): void
    {
        $this->actingAs($this->staff());
        $this->put('/admin/global-settings/header/settings', $this->settingsInput())->assertRedirect('/admin/global-settings/header');
        $this->get('/api/public/site-settings')->assertJsonPath('settings.branding.site_name', "Bably's Club");
        $this->get('/admin/global-settings/header')->assertInertia(fn ($page) => $page
            ->where('settings.draft.branding.site_name', 'Updated Bablys')->where('settings.draft_version', 1));
        self::assertSame(1, DB::table('bablys_site_settings_revisions')->count());
        $this->post('/admin/global-settings/header/settings/publish', ['expected_draft_version' => 1, 'expected_publication_serial' => 0])
            ->assertRedirect('/admin/global-settings/header');
        $this->get('/api/public/site-settings')->assertJsonPath('settings.branding.site_name', 'Updated Bablys');
        self::assertSame(1, DB::table('bablys_cms_publication_events')->count());
    }

    public function test_footer_saves_keep_brand_fields_that_other_editor_set(): void
    {
        $this->actingAs($this->staff());
        $payload = $this->settingsInput(0, 'Bablys Company');
        $this->put('/admin/global-settings/header/settings', $payload)->assertRedirect();
        $payload['expected_version'] = 1;
        $payload['contact']['office_address'] = 'Gulshan, Dhaka';
        $this->put('/admin/global-settings/footer/settings', $payload)->assertRedirect();
        $this->get('/admin/global-settings/header')->assertInertia(fn ($page) => $page
            ->where('settings.draft.branding.site_name', 'Bablys Company')
            ->where('settings.draft.contact.office_address', 'Gulshan, Dhaka'));
    }

    public function test_stale_settings_save_and_stale_publish_are_rejected(): void
    {
        $this->actingAs($this->staff());
        $this->put('/admin/global-settings/header/settings', $this->settingsInput())->assertRedirect();
        $this->from('/admin/global-settings/footer')
            ->put('/admin/global-settings/footer/settings', $this->settingsInput())->assertSessionHasErrors('expected_version');
        $this->post('/admin/global-settings/header/settings/publish', ['expected_draft_version' => 1, 'expected_publication_serial' => 0])->assertRedirect();
        $this->from('/admin/global-settings/header')
            ->post('/admin/global-settings/header/settings/publish', ['expected_draft_version' => 1, 'expected_publication_serial' => 0])
            ->assertSessionHasErrors('expected_publication_serial');
        self::assertSame(1, DB::table('bablys_cms_publication_events')->count());
    }

    public function test_named_menu_location_is_created_only_for_its_correct_section(): void
    {
        $this->actingAs($this->staff());
        $this->post('/admin/global-settings/header/menus', ['menu_key' => 'main-header'])->assertRedirect('/admin/global-settings/header');
        $this->post('/admin/global-settings/footer/menus', ['menu_key' => 'footer-links'])->assertRedirect('/admin/global-settings/footer');
        self::assertSame(['footer-links', 'main-header'], DB::table('bablys_navigation_menus')->orderBy('menu_key')->pluck('menu_key')->all());
        $this->from('/admin/global-settings/header')
            ->post('/admin/global-settings/header/menus', ['menu_key' => 'footer-domestic'])->assertSessionHasErrors('menu_key');
        $this->from('/admin/global-settings/header')
            ->post('/admin/global-settings/header/menus', ['menu_key' => 'not-allowed'])->assertSessionHasErrors('menu_key');
        self::assertSame(2, DB::table('bablys_navigation_menus')->count());
    }

    public function test_menu_save_publish_and_public_only_projection(): void
    {
        $this->actingAs($this->staff());
        $this->post('/admin/global-settings/header/menus', ['menu_key' => 'main-header'])->assertRedirect();
        $id = (int) DB::table('bablys_navigation_menus')->where('menu_key', 'main-header')->value('id');
        $parent = $this->item('Tours', '/tours');
        $child = $this->item('Domestic', '/tours?category=domestic', (string) $parent['id']);
        $this->put('/admin/global-settings/header/menus/'.$id, [
            'expected_version' => 0, 'name' => 'Main Header', 'items' => [$parent, $child],
        ])->assertRedirect('/admin/global-settings/header');
        $this->get('/api/public/navigation-menus')->assertJsonMissingPath('menus.main-header');
        $this->post('/admin/global-settings/header/menus/'.$id.'/publish', [
            'expected_draft_version' => 1, 'expected_publication_serial' => 0,
        ])->assertRedirect('/admin/global-settings/header');
        $this->get('/api/public/navigation-menus')->assertJsonPath('menus.main-header.items.1.label', 'Domestic');
        $this->get('/admin/global-settings/header')->assertInertia(fn ($page) => $page
            ->where('menus.0.menu.draft_version', 1)->where('menus.0.menu.publication_serial', 1));
    }

    public function test_wrong_section_cannot_modify_or_publish_menu(): void
    {
        $this->actingAs($this->staff());
        $this->post('/admin/global-settings/footer/menus', ['menu_key' => 'footer-global'])->assertRedirect();
        $id = (int) DB::table('bablys_navigation_menus')->where('menu_key', 'footer-global')->value('id');
        $this->put('/admin/global-settings/header/menus/'.$id, [
            'expected_version' => 0, 'name' => 'Global', 'items' => [$this->item()],
        ])->assertNotFound();
        $this->post('/admin/global-settings/header/menus/'.$id.'/publish', [
            'expected_draft_version' => 0, 'expected_publication_serial' => 0,
        ])->assertNotFound();
        self::assertSame(0, (int) DB::table('bablys_navigation_menus')->where('id', $id)->value('draft_version'));
    }

    public function test_unsafe_menu_urls_are_rejected_by_existing_domain_validator(): void
    {
        $this->actingAs($this->staff());
        $this->post('/admin/global-settings/header/menus', ['menu_key' => 'main-header'])->assertRedirect();
        $id = (int) DB::table('bablys_navigation_menus')->where('menu_key', 'main-header')->value('id');
        $bad = $this->item('Unsafe', 'javascript:alert(1)');
        $this->from('/admin/global-settings/header')->put('/admin/global-settings/header/menus/'.$id,
            ['expected_version' => 0, 'name' => 'Main Header', 'items' => [$bad]])->assertSessionHasErrors('items.0.url');
        self::assertSame(0, DB::table('bablys_navigation_menu_revisions')->count());
    }

    public function test_routes_keep_cms_permission_and_publication_throttles(): void
    {
        foreach ([
            'admin.global-settings.overview', 'admin.global-settings.header', 'admin.global-settings.footer',
            'admin.global-settings.header.settings.save', 'admin.global-settings.footer.settings.publish',
            'admin.global-settings.menus.create', 'admin.global-settings.menus.save', 'admin.global-settings.menus.publish',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, $name);
            $middleware = implode('|', $route->gatherMiddleware());
            self::assertStringContainsString('EnsureCmsStaff', $middleware);
            self::assertStringContainsString('settings.manage', $middleware);
        }
        $publish = Route::getRoutes()->getByName('admin.global-settings.menus.publish');
        self::assertNotNull($publish);
        self::assertSame(['POST'], $publish->methods());
        self::assertStringContainsString('throttle:', implode('|', $publish->gatherMiddleware()));
    }

    public function test_native_sidebar_shows_header_footer_only_for_settings_managers(): void
    {
        $this->actingAs($this->staff());
        $this->getJson('/admin/internal/navigation')->assertOk()
            ->assertJsonFragment(['href' => '/admin/global-settings/header'])
            ->assertJsonFragment(['href' => '/admin/global-settings/footer']);
        $this->actingAs($this->staff('content_editor'));
        $this->getJson('/admin/internal/navigation')->assertOk()
            ->assertJsonMissing(['href' => '/admin/global-settings/header'])
            ->assertJsonMissing(['href' => '/admin/global-settings/footer']);
    }

    public function test_frontend_uses_one_admin_layout_and_never_edits_public_sidebar(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/pages/admin/global-settings/workspace.tsx'));
        self::assertStringContainsString('<BablysAdminLayout>', $source);
        self::assertStringContainsString('Save draft', $source);
        self::assertStringContainsString('Publish menu', $source);
        self::assertStringContainsString('Publish saved settings', $source);
        self::assertStringNotContainsString('<AppSidebar', $source);
    }
}
