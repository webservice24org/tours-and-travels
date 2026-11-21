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

/** P4A.3 publication/rollback HTTP, draft isolation, RBAC and event-log integration. */
final class CmsPublishingPreviewRollbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Pre-RefreshDatabase guard prevents accidental local development DB wipes.
        $env = (string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? ''));
        $driver = (string) (getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? ''));
        $database = (string) (getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? ''));
        if ($env !== 'testing' || $driver !== 'mysql'
            || preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*_test\z/D', $database) !== 1
            || str_ends_with($database, 'race_test')) {
            throw new \RuntimeException('P4A.3 requires APP_ENV=testing, DB_CONNECTION=mysql, and a dedicated non-race *_test database.');
        }
        parent::setUp();
        // Publishing behavior tests must not share persistent throttle counters.
        // HTTP rate limits remain active in production and are asserted below.
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
    private function settings(int $version = 0, string $name = 'Draft Bablys'): array
    {
        $values = app(BablysSiteSettings::class)->defaults();
        $values['branding']['site_name'] = $name;
        return ['expected_version' => $version, ...$values];
    }

    /** @return array<string, mixed> */
    private function versionInput(int $draft = 1, int $serial = 0): array
    {
        return ['expected_draft_version' => $draft, 'expected_publication_serial' => $serial];
    }

    private function createMenu(): int
    {
        $this->post('/admin/navigation-menus', ['menu_key' => 'main-header', 'name' => 'Main Header'])->assertRedirect();
        return (int) DB::table('bablys_navigation_menus')->where('menu_key', 'main-header')->value('id');
    }

    /** @return array<string, mixed> */
    private function menuItem(string $label, string $url): array
    {
        return [
            'id' => (string) Str::uuid(), 'label' => $label, 'kind' => 'internal',
            'url' => $url, 'parent_id' => null, 'new_tab' => false,
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function menuDraft(int $id, array $items, int $version = 0, string $name = 'Main Header'): void
    {
        $this->put('/admin/navigation-menus/'.$id, [
            'expected_version' => $version, 'name' => $name, 'items' => $items,
        ])->assertRedirect();
    }

    public function test_guests_and_customers_cannot_preview_publish_or_rollback(): void
    {
        $this->get('/admin/cms-publishing')->assertRedirect(route('login'));
        $this->get('/admin/cms-publishing/site-settings/preview')->assertRedirect(route('login'));
        $this->post('/admin/cms-publishing/site-settings/publish', $this->versionInput())->assertRedirect(route('login'));
        $this->post('/admin/cms-publishing/site-settings/rollback', [...$this->versionInput(), 'publication_event_id' => 1])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
        $this->get('/admin/cms-publishing')->assertForbidden();
        $this->get('/admin/cms-publishing/site-settings/preview')->assertForbidden();
        $this->post('/admin/cms-publishing/site-settings/publish', $this->versionInput())->assertForbidden();
        self::assertSame(0, DB::table('bablys_cms_publication_events')->count());
    }

    public function test_non_privileged_staff_cannot_publish(): void
    {
        $this->actingAs($this->staff('content_editor'));
        $this->get('/admin/cms-publishing')->assertForbidden();
        $this->post('/admin/cms-publishing/site-settings/publish', $this->versionInput())->assertForbidden();
        $this->post('/admin/cms-publishing/navigation-menus/1/publish', $this->versionInput())->assertForbidden();
    }

    public function test_previews_are_private_and_do_not_expose_drafts_to_public(): void
    {
        $this->actingAs($this->staff());
        $this->put('/admin/site-settings', $this->settings())->assertRedirect();
        $this->get('/admin/cms-publishing')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn ($page) => $page->component('admin/cms-publishing/index')
                ->where('settings.draft_version', 1)->where('settings.published_version', 0));
        $this->get('/admin/cms-publishing/site-settings/preview')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn ($page) => $page->component('admin/cms-publishing/preview')
                ->where('snapshot.draft.branding.site_name', 'Draft Bablys')
                ->where('snapshot.published.branding.site_name', "Bably's Club"));
        $this->get('/api/public/site-settings')->assertOk()
            ->assertJsonPath('settings.branding.site_name', "Bably's Club")
            ->assertDontSee('Draft Bablys');
    }

    public function test_settings_cannot_publish_without_saving_a_draft(): void
    {
        $this->actingAs($this->staff());
        $this->from('/admin/cms-publishing/site-settings/preview')
            ->post('/admin/cms-publishing/site-settings/publish', $this->versionInput(0, 0))
            ->assertSessionHasErrors('expected_draft_version');
        self::assertSame(0, DB::table('bablys_cms_publication_events')->count());
    }

    public function test_publishing_site_settings_updates_only_public_snapshot_and_records_actor(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->put('/admin/site-settings', $this->settings())->assertRedirect();
        $this->post('/admin/cms-publishing/site-settings/publish', $this->versionInput())->assertRedirect();
        $row = DB::table('bablys_site_settings')->where('scope', 'global')->first();
        self::assertNotNull($row);
        self::assertSame(1, (int) $row->draft_version);
        self::assertSame(1, (int) $row->published_version);
        self::assertSame(1, (int) $row->publication_serial);
        self::assertSame((int) $user->getKey(), (int) $row->published_by);
        self::assertSame(1, DB::table('bablys_cms_publication_events')->count());
        $this->get('/api/public/site-settings')->assertOk()->assertJsonPath('settings.branding.site_name', 'Draft Bablys')
            ->assertDontSee('draft_payload')->assertDontSee('publication_serial');
    }

    public function test_new_settings_draft_does_not_change_published_payload(): void
    {
        $this->actingAs($this->staff());
        $this->put('/admin/site-settings', $this->settings())->assertRedirect();
        $this->post('/admin/cms-publishing/site-settings/publish', $this->versionInput())->assertRedirect();
        $this->put('/admin/site-settings', $this->settings(1, 'UNPUBLISHED BRAND'))->assertRedirect();
        $this->get('/api/public/site-settings')->assertOk()->assertJsonPath('settings.branding.site_name', 'Draft Bablys')
            ->assertDontSee('UNPUBLISHED BRAND');
    }

    public function test_stale_draft_blocks_settings_publish_without_new_event(): void
    {
        $this->actingAs($this->staff());
        $this->put('/admin/site-settings', $this->settings())->assertRedirect();
        $this->put('/admin/site-settings', $this->settings(1, 'New version'))->assertRedirect();
        $this->from('/admin/cms-publishing/site-settings/preview')
            ->post('/admin/cms-publishing/site-settings/publish', $this->versionInput(1, 0))
            ->assertSessionHasErrors('expected_draft_version');
        self::assertSame(0, DB::table('bablys_cms_publication_events')->count());
    }

    public function test_repeated_settings_publish_with_stale_serial_is_rejected(): void
    {
        $this->actingAs($this->staff());
        $this->put('/admin/site-settings', $this->settings())->assertRedirect();
        $this->post('/admin/cms-publishing/site-settings/publish', $this->versionInput())->assertRedirect();
        $this->from('/admin/cms-publishing/site-settings/preview')
            ->post('/admin/cms-publishing/site-settings/publish', $this->versionInput())
            ->assertSessionHasErrors('expected_publication_serial');
        self::assertSame(1, DB::table('bablys_cms_publication_events')->count());
    }

    public function test_settings_rollback_restores_prior_publication_but_not_draft(): void
    {
        $this->actingAs($this->staff());
        $this->put('/admin/site-settings', $this->settings(0, 'Brand A'))->assertRedirect();
        $this->post('/admin/cms-publishing/site-settings/publish', $this->versionInput(1, 0))->assertRedirect();
        $event = (int) DB::table('bablys_cms_publication_events')->where('subject_type', 'settings')->value('id');
        $this->put('/admin/site-settings', $this->settings(1, 'Brand B'))->assertRedirect();
        $this->post('/admin/cms-publishing/site-settings/publish', $this->versionInput(2, 1))->assertRedirect();
        $this->post('/admin/cms-publishing/site-settings/rollback', [
            ...$this->versionInput(2, 2), 'publication_event_id' => $event,
        ])->assertRedirect();
        $this->get('/api/public/site-settings')->assertOk()->assertJsonPath('settings.branding.site_name', 'Brand A');
        $this->get('/admin/cms-publishing/site-settings/preview')->assertInertia(fn ($page) => $page
            ->where('snapshot.draft.branding.site_name', 'Brand B')
            ->where('snapshot.published.branding.site_name', 'Brand A')
            ->where('snapshot.publication_serial', 3));
        self::assertSame(['publish','publish','rollback'], DB::table('bablys_cms_publication_events')->orderBy('id')->pluck('action')->all());
    }

    public function test_menu_publish_requires_saved_draft_and_exposes_no_private_links(): void
    {
        $this->actingAs($this->staff());
        $id = $this->createMenu();
        $this->from('/admin/cms-publishing')->post('/admin/cms-publishing/navigation-menus/'.$id.'/publish', $this->versionInput(0, 0))
            ->assertSessionHasErrors('expected_draft_version');
        $this->menuDraft($id, [$this->menuItem('Approved Tours', '/tours')]);
        $this->get('/api/public/navigation-menus')->assertOk()->assertDontSee('Approved Tours');
        $this->post('/admin/cms-publishing/navigation-menus/'.$id.'/publish', $this->versionInput())->assertRedirect();
        $this->get('/api/public/navigation-menus')->assertOk()->assertJsonPath('menus.main-header.items.0.label', 'Approved Tours')
            ->assertDontSee('draft_items')->assertDontSee('publication_serial');
    }

    public function test_menu_draft_edit_does_not_change_live_menu_until_republished(): void
    {
        $this->actingAs($this->staff());
        $id = $this->createMenu();
        $this->menuDraft($id, [$this->menuItem('Tours A', '/tours')]);
        $this->post('/admin/cms-publishing/navigation-menus/'.$id.'/publish', $this->versionInput())->assertRedirect();
        $this->menuDraft($id, [$this->menuItem('Tours B', '/tours')], 1);
        $this->get('/api/public/navigation-menus')->assertJsonPath('menus.main-header.items.0.label', 'Tours A')->assertDontSee('Tours B');
    }

    public function test_menu_rollback_restores_published_links_and_keeps_draft(): void
    {
        $this->actingAs($this->staff());
        $id = $this->createMenu();
        $this->menuDraft($id, [$this->menuItem('Tours A', '/tours')]);
        $this->post('/admin/cms-publishing/navigation-menus/'.$id.'/publish', $this->versionInput(1, 0))->assertRedirect();
        $event = (int) DB::table('bablys_cms_publication_events')->where('subject_type','navigation')->value('id');
        $this->menuDraft($id, [$this->menuItem('Tours B', '/tours')], 1);
        $this->post('/admin/cms-publishing/navigation-menus/'.$id.'/publish', $this->versionInput(2, 1))->assertRedirect();
        $this->post('/admin/cms-publishing/navigation-menus/'.$id.'/rollback', [
            ...$this->versionInput(2, 2), 'publication_event_id' => $event,
        ])->assertRedirect();
        $this->get('/api/public/navigation-menus')->assertJsonPath('menus.main-header.items.0.label', 'Tours A');
        $this->get('/admin/cms-publishing/navigation-menus/'.$id.'/preview')->assertInertia(fn ($page) => $page
            ->where('snapshot.draft.items.0.label', 'Tours B')->where('snapshot.published.items.0.label', 'Tours A'));
        self::assertSame(3, (int) DB::table('bablys_navigation_menus')->where('id', $id)->value('publication_serial'));
    }

    public function test_cross_menu_rollback_event_cannot_be_reused_for_another_menu(): void
    {
        $this->actingAs($this->staff());
        $id = $this->createMenu();
        $this->menuDraft($id, [$this->menuItem('Tour', '/tours')]);
        $this->post('/admin/cms-publishing/navigation-menus/'.$id.'/publish', $this->versionInput(1, 0))->assertRedirect();
        $event = (int) DB::table('bablys_cms_publication_events')->where('subject_type','navigation')->value('id');
        $this->post('/admin/navigation-menus', ['menu_key'=>'footer-links', 'name'=>'Footer'])->assertRedirect();
        $other = (int) DB::table('bablys_navigation_menus')->where('menu_key','footer-links')->value('id');
        $this->menuDraft($other, [$this->menuItem('About', '/about')]);
        $this->post('/admin/cms-publishing/navigation-menus/'.$other.'/publish', $this->versionInput(1, 0))->assertRedirect();
        $this->from('/admin/cms-publishing')->post('/admin/cms-publishing/navigation-menus/'.$other.'/rollback', [
            ...$this->versionInput(1, 1), 'publication_event_id' => $event,
        ])->assertSessionHasErrors('publication_event_id');
        self::assertSame(1, (int) DB::table('bablys_navigation_menus')->where('id', $other)->value('publication_serial'));
    }

    public function test_published_menu_cannot_be_deleted_via_old_draft_endpoint(): void
    {
        $this->actingAs($this->staff());
        $id = $this->createMenu();
        $this->menuDraft($id, [$this->menuItem('Tours', '/tours')]);
        $this->post('/admin/cms-publishing/navigation-menus/'.$id.'/publish', $this->versionInput(1,0))->assertRedirect();
        $this->from('/admin/navigation-menus')->delete('/admin/navigation-menus/'.$id, ['expected_version' => 1])
            ->assertSessionHasErrors('menu');
        self::assertSame(1, DB::table('bablys_navigation_menus')->count());
    }

    public function test_get_routes_are_read_only_and_post_routes_require_permissions(): void
    {
        $names = ['index', 'settings.preview', 'navigation.preview', 'settings.publish',
            'settings.rollback', 'navigation.publish', 'navigation.rollback'];
        foreach ($names as $suffix) {
            $route = Route::getRoutes()->getByName('admin.cms-publishing.'.$suffix);
            self::assertNotNull($route);
            $middlewares = implode('|', $route->gatherMiddleware());
            self::assertStringContainsString('EnsureCmsStaff', $middlewares);
            self::assertStringContainsString('settings.manage', $middlewares);
            self::assertSame(in_array($suffix, ['index','settings.preview','navigation.preview'], true) ? ['GET','HEAD'] : ['POST'], $route->methods());
        }
        // Assert the real application routes retain their rate limits, even though
        // behavioral test requests bypass throttle middleware to avoid leaked counts.
        foreach ([
            'settings.publish' => 'throttle:10,1',
            'settings.rollback' => 'throttle:5,1',
            'navigation.publish' => 'throttle:10,1',
            'navigation.rollback' => 'throttle:5,1',
        ] as $suffix => $expectedThrottle) {
            $actionRoute = Route::getRoutes()->getByName('admin.cms-publishing.'.$suffix);
            self::assertNotNull($actionRoute);
            self::assertContains($expectedThrottle, $actionRoute->gatherMiddleware());
        }
        self::assertNull(Route::getRoutes()->getByName('admin.site-settings.publish'));
        self::assertNull(Route::getRoutes()->getByName('admin.navigation-menus.publish'));
    }

    public function test_new_admin_pages_reuse_only_the_existing_bablys_admin_layout(): void
    {
        foreach (['index','preview'] as $page) {
            $source = (string) file_get_contents(base_path('resources/js/pages/admin/cms-publishing/'.$page.'.tsx'));
            self::assertStringContainsString('<BablysAdminLayout>', $source);
            self::assertStringNotContainsString('<AppSidebar', $source);
            self::assertStringNotContainsString('<AppLayout', $source);
        }
    }
}
