<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Publishing\BablysCmsPublishing;
use App\Domain\Cms\Settings\BablysSiteSettings;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** P4A.4 end-to-end published-only header/footer projection. */
final class PublicSiteChromeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Guard BEFORE RefreshDatabase setup; never run against the dev/race DB.
        $env = (string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? ''));
        $driver = (string) (getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? ''));
        $database = (string) (getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? ''));
        if ($env !== 'testing' || $driver !== 'mysql'
            || preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*_test\z/D', $database) !== 1
            || str_ends_with($database, 'race_test')) {
            throw new \RuntimeException('P4A.4 requires APP_ENV=testing and a dedicated non-race MySQL *_test database.');
        }
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    private function staff(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        CmsRole::query()->where('key', 'super_admin')->firstOrFail()->users()->attach($user->getKey());
        return $user;
    }

    /** @return array<string, mixed> */
    private function settings(int $version, string $name, string $email = ''): array
    {
        $data = app(BablysSiteSettings::class)->defaults();
        $data['branding']['site_name'] = $name;
        $data['contact']['email'] = $email;
        $data['contact']['office_address'] = 'Dhaka, Bangladesh';
        $data['social']['facebook'] = 'https://www.facebook.com/bablystravel';
        return ['expected_version' => $version, ...$data];
    }

    /** @return array{id: string, label: string, kind: string, url: string, parent_id: ?string, new_tab: bool} */
    private function item(string $name, string $url, ?string $parent = null, string $kind = 'internal', bool $newTab = false): array
    {
        return ['id' => (string) Str::uuid(), 'label' => $name, 'kind' => $kind,
            'url' => $url, 'parent_id' => $parent, 'new_tab' => $newTab];
    }

    private function menu(string $key = 'main-header'): int
    {
        $this->post('/admin/navigation-menus', ['menu_key' => $key, 'name' => $key])->assertRedirect();
        return (int) DB::table('bablys_navigation_menus')->where('menu_key', $key)->value('id');
    }

    /** @param list<array<string, mixed>> $items */
    private function saveMenu(int $id, array $items, int $version = 0): void
    {
        $this->put('/admin/navigation-menus/'.$id, [
            'expected_version' => $version, 'name' => 'CMS Navigation', 'items' => $items,
        ])->assertRedirect();
    }

    public function test_public_endpoint_starts_with_safe_defaults_and_empty_published_menus(): void
    {
        $this->getJson('/api/public/site-chrome')->assertOk()
            ->assertJsonPath('settings.branding.site_name', "Bably's Club")
            ->assertJsonPath('settings.branding.tagline', 'Tours & Travels')
            ->assertExactJson(['settings' => app(BablysSiteSettings::class)->defaults(), 'menus' => []]);
    }

    public function test_saved_settings_draft_is_not_visible_until_published(): void
    {
        $this->actingAs($this->staff());
        $this->put('/admin/site-settings', $this->settings(0, 'Secret Draft Brand', 'draft@bablys.example'))->assertRedirect();
        $this->getJson('/api/public/site-chrome')->assertOk()
            ->assertJsonPath('settings.branding.site_name', "Bably's Club")
            ->assertDontSee('Secret Draft Brand')->assertDontSee('draft@bablys.example');
        $this->getJson('/api/public/site-settings')->assertJsonPath('settings.branding.site_name', "Bably's Club");
    }

    public function test_approved_site_settings_are_reflected_in_public_chrome(): void
    {
        $actor = $this->staff();
        $this->actingAs($actor)->put('/admin/site-settings', $this->settings(0, 'Bablys Travel Official', 'hello@bablys.example'))->assertRedirect();
        app(BablysCmsPublishing::class)->publishSettings(1, 0, (int) $actor->getKey());
        $this->getJson('/api/public/site-chrome')->assertOk()
            ->assertJsonPath('settings.branding.site_name', 'Bablys Travel Official')
            ->assertJsonPath('settings.contact.email', 'hello@bablys.example')
            ->assertJsonPath('settings.contact.office_address', 'Dhaka, Bangladesh')
            ->assertJsonPath('settings.social.facebook', 'https://www.facebook.com/bablystravel')
            ->assertDontSee('draft_payload')->assertDontSee('draft_version');
    }

    public function test_subsequent_unpublished_draft_never_changes_public_brand(): void
    {
        $actor = $this->staff();
        $this->actingAs($actor)->put('/admin/site-settings', $this->settings(0, 'Visible Brand'))->assertRedirect();
        app(BablysCmsPublishing::class)->publishSettings(1, 0, (int) $actor->getKey());
        $this->put('/admin/site-settings', $this->settings(1, 'Unpublished Brand'))->assertRedirect();
        $this->getJson('/api/public/site-chrome')->assertJsonPath('settings.branding.site_name', 'Visible Brand')
            ->assertDontSee('Unpublished Brand');
    }

    public function test_unpublished_header_and_footer_menu_drafts_are_hidden(): void
    {
        $this->actingAs($this->staff());
        $header = $this->menu();
        $footer = $this->menu('footer-links');
        $this->saveMenu($header, [$this->item('Private Header Draft', '/tours')]);
        $this->saveMenu($footer, [$this->item('Private Footer Draft', '/about')]);
        $this->getJson('/api/public/site-chrome')->assertOk()->assertExactJson([
            'settings' => app(BablysSiteSettings::class)->defaults(), 'menus' => [],
        ])->assertDontSee('Private Header Draft')->assertDontSee('Private Footer Draft');
    }

    public function test_published_nested_header_and_footer_keep_source_order_and_link_safety_metadata(): void
    {
        $actor = $this->staff();
        $this->actingAs($actor);
        $headerId = $this->menu();
        $root = $this->item('Tours', '/tours');
        $child = $this->item('International', '/tours?category=international', $root['id']);
        $partner = $this->item('Partner', 'https://partner.example.com/travel', null, 'external', true);
        $this->saveMenu($headerId, [$root, $child, $partner]);
        app(BablysCmsPublishing::class)->publishMenu($headerId, 1, 0, (int) $actor->getKey());
        $footerId = $this->menu('footer-links');
        $this->saveMenu($footerId, [$this->item('About Us', '/about')]);
        app(BablysCmsPublishing::class)->publishMenu($footerId, 1, 0, (int) $actor->getKey());
        $this->getJson('/api/public/site-chrome')->assertOk()
            ->assertJsonPath('menus.main-header.items.0.label', 'Tours')
            ->assertJsonPath('menus.main-header.items.1.parent_id', $root['id'])
            ->assertJsonPath('menus.main-header.items.2.kind', 'external')
            ->assertJsonPath('menus.main-header.items.2.new_tab', true)
            ->assertJsonPath('menus.footer-links.items.0.label', 'About Us')
            ->assertDontSee('draft_items')->assertDontSee('published_by');
    }

    public function test_published_menu_is_unchanged_by_new_draft(): void
    {
        $actor = $this->staff();
        $this->actingAs($actor);
        $id = $this->menu();
        $this->saveMenu($id, [$this->item('Approved Tours', '/tours')]);
        app(BablysCmsPublishing::class)->publishMenu($id, 1, 0, (int) $actor->getKey());
        $this->saveMenu($id, [$this->item('Private New Menu', '/secret')], 1);
        $this->getJson('/api/public/site-chrome')->assertJsonPath('menus.main-header.items.0.label', 'Approved Tours')
            ->assertDontSee('Private New Menu');
    }

    public function test_rollback_changes_public_content_without_exposing_saved_drafts(): void
    {
        $actor = $this->staff();
        $this->actingAs($actor);
        $publishing = app(BablysCmsPublishing::class);
        $this->put('/admin/site-settings', $this->settings(0, 'Brand A'))->assertRedirect();
        $publishing->publishSettings(1, 0, (int) $actor->getKey());
        $eventId = (int) DB::table('bablys_cms_publication_events')->where('subject_type', 'settings')->value('id');
        $this->put('/admin/site-settings', $this->settings(1, 'Brand B'))->assertRedirect();
        $publishing->publishSettings(2, 1, (int) $actor->getKey());
        $publishing->rollbackSettings($eventId, 2, 2, (int) $actor->getKey());
        $this->getJson('/api/public/site-chrome')->assertJsonPath('settings.branding.site_name', 'Brand A')
            ->assertDontSee('Brand B');
        self::assertSame(2, (int) DB::table('bablys_site_settings')->where('scope', 'global')->value('draft_version'));
    }

    public function test_public_projection_has_no_store_headers_and_never_exports_staff_secrets(): void
    {
        $response = $this->getJson('/api/public/site-chrome')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $cache = (string) $response->headers->get('Cache-Control');
        foreach (['no-store', 'max-age=0'] as $directive) {
            self::assertContains($directive, array_map('trim', explode(',', $cache)));
        }
        foreach (['draft_payload', 'draft_items', 'draft_version', 'actor_id', 'contact_email_encrypted', 'private_key', 'payment_state'] as $secret) {
            $response->assertDontSee($secret);
        }
        $this->postJson('/api/public/site-chrome')->assertStatus(405);
    }
}
