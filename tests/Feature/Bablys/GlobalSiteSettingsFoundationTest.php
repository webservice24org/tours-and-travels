<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Settings\BablysSiteSettings;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** P4A.1 typed settings foundation and unpublished-draft isolation. */
final class GlobalSiteSettingsFoundationTest extends TestCase
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
            throw new \RuntimeException('P4A.1 requires a dedicated, non-race MySQL *_test database and APP_ENV=testing.');
        }
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    private function staff(string $role = 'super_admin'): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        CmsRole::query()->where('key', $role)->firstOrFail()->users()->attach($user->getKey());
        return $user;
    }

    /** @return array<string, mixed> */
    private function editPayload(int $version = 0, string $name = 'Bablys Updated'): array
    {
        return ['expected_version' => $version, ...app(BablysSiteSettings::class)->defaults(),
            'branding' => ['site_name' => $name, 'tagline' => 'Tours & Travels', 'legal_name' => ''],
        ];
    }

    public function test_guest_and_customer_cannot_enter_admin_settings(): void
    {
        $this->get('/admin/site-settings')->assertRedirect(route('login'));
        $this->put('/admin/site-settings', $this->editPayload())->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->get('/admin/site-settings')->assertForbidden();
        $this->put('/admin/site-settings', $this->editPayload())->assertForbidden();
    }

    public function test_non_privileged_staff_cannot_edit_global_settings(): void
    {
        $this->actingAs($this->staff('content_editor'))
            ->get('/admin/site-settings')->assertForbidden();
        $this->put('/admin/site-settings', $this->editPayload())->assertForbidden();
        self::assertSame(0, DB::table('bablys_site_settings_revisions')->count());
    }

    public function test_super_admin_gets_private_draft_editor_and_default_public_values(): void
    {
        $this->actingAs($this->staff())->get('/admin/site-settings')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn ($page) => $page->component('admin/site-settings/index')
                ->where('draft_version', 0)
                ->where('published_version', 0)
                ->where('draft.branding.site_name', "Bably's Club")
                ->where('can_publish', false));
        $this->get('/api/public/site-settings')->assertOk()
            ->assertJsonPath('settings.branding.site_name', "Bably's Club");
    }

    public function test_draft_saves_create_audit_revision_and_never_update_public(): void
    {
        $actor = $this->staff();
        $this->actingAs($actor)->put('/admin/site-settings', $this->editPayload())
            ->assertRedirect(route('admin.site-settings.index'));
        $row = DB::table('bablys_site_settings')->first();
        self::assertNotNull($row);
        self::assertSame(1, (int) $row->draft_version);
        self::assertSame(0, (int) $row->published_version);
        self::assertSame((int) $actor->getKey(), (int) $row->draft_updated_by);
        self::assertSame(1, DB::table('bablys_site_settings_revisions')->count());
        $this->get('/admin/site-settings')->assertOk()->assertInertia(fn ($page) => $page
            ->where('draft.branding.site_name', 'Bablys Updated')
            ->where('published.branding.site_name', "Bably's Club"));
        $this->get('/api/public/site-settings')->assertOk()
            ->assertJsonPath('settings.branding.site_name', "Bably's Club");
    }

    public function test_optimistic_version_rejects_stale_writes_without_new_revision(): void
    {
        $this->actingAs($this->staff());
        $this->put('/admin/site-settings', $this->editPayload())->assertRedirect();
        $this->from('/admin/site-settings')->put('/admin/site-settings', $this->editPayload(0, 'Stale'))
            ->assertRedirect('/admin/site-settings')->assertSessionHasErrors('expected_version');
        self::assertSame(1, DB::table('bablys_site_settings_revisions')->count());
        $this->get('/admin/site-settings')->assertOk()->assertInertia(fn ($page) => $page
            ->where('draft.branding.site_name', 'Bablys Updated'));
    }

    public function test_sequential_saves_increment_versions_and_preserve_revisions(): void
    {
        $this->actingAs($this->staff());
        $this->put('/admin/site-settings', $this->editPayload())->assertRedirect();
        $this->put('/admin/site-settings', $this->editPayload(1, 'Revised Brand'))->assertRedirect();
        self::assertSame(2, DB::table('bablys_site_settings_revisions')->count());
        self::assertSame([1, 2], DB::table('bablys_site_settings_revisions')->orderBy('draft_version')
            ->pluck('draft_version')->map(static fn ($value): int => (int) $value)->all());
        $this->get('/admin/site-settings')->assertOk()->assertInertia(fn ($page) => $page
            ->where('draft_version', 2)->where('draft.branding.site_name', 'Revised Brand'));
    }

    public function test_rejects_unsafe_social_urls_and_html_tags(): void
    {
        $this->actingAs($this->staff());
        $payload = $this->editPayload();
        $payload['social']['facebook'] = 'javascript:alert(1)';
        $payload['branding']['site_name'] = '<script>alert(1)</script>';
        $this->from('/admin/site-settings')->put('/admin/site-settings', $payload)
            ->assertSessionHasErrors(['social.facebook', 'branding.site_name']);
        self::assertSame(0, DB::table('bablys_site_settings_revisions')->count());
    }

    public function test_public_projection_drops_untrusted_editor_fields_and_secret_payload(): void
    {
        $this->actingAs($this->staff());
        $payload = $this->editPayload();
        $payload['api_secret'] = 'NEVER-PUBLIC';
        $payload['branding']['private_key'] = 'NEVER-PUBLIC';
        $this->put('/admin/site-settings', $payload)->assertRedirect();
        $this->get('/api/public/site-settings')->assertOk()->assertDontSee('NEVER-PUBLIC')
            ->assertDontSee('draft_version')->assertDontSee('draft_payload')
            ->assertDontSee('updated_by');
        self::assertStringNotContainsString('NEVER-PUBLIC', (string) DB::table('bablys_site_settings')->value('draft_payload'));
    }

    public function test_routes_are_guarded_and_no_publish_endpoint_exists_yet(): void
    {
        foreach (['admin.site-settings.index', 'admin.site-settings.update'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route);
            self::assertStringContainsString('EnsureCmsStaff', implode('|', $route->gatherMiddleware()));
            self::assertStringContainsString('settings.manage', implode('|', $route->gatherMiddleware()));
        }
        self::assertSame(['GET', 'HEAD'], Route::getRoutes()->getByName('admin.site-settings.index')?->methods());
        self::assertSame(['PUT'], Route::getRoutes()->getByName('admin.site-settings.update')?->methods());
        self::assertNull(Route::getRoutes()->getByName('admin.site-settings.publish'));
    }

    public function test_frontend_reuses_single_admin_layout_without_public_sidebar(): void
    {
        $code = (string) file_get_contents(base_path('resources/js/pages/admin/site-settings/index.tsx'));
        self::assertStringContainsString('<BablysAdminLayout>', $code);
        self::assertStringNotContainsString('<AppSidebar', $code);
        self::assertStringContainsString('Not published', $code);
    }
}
