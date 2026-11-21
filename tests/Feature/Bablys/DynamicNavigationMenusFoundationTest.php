<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/** P4A.2 drafts-only dynamic menu CRUD, nesting and public projection boundaries. */
final class DynamicNavigationMenusFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Must run before RefreshDatabase is initialized: prevents accidental dev DB wipes.
        $env = (string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? ''));
        $driver = (string) (getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? ''));
        $database = (string) (getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? ''));
        if ($env !== 'testing' || $driver !== 'mysql'
            || preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*_test\z/D', $database) !== 1
            || str_ends_with($database, 'race_test')) {
            throw new \RuntimeException('P4A.2 requires APP_ENV=testing and a dedicated non-race MySQL *_test database.');
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

    private function createMenu(string $key = 'main-header', string $name = 'Main Header'): int
    {
        $this->post('/admin/navigation-menus', ['menu_key' => $key, 'name' => $name])
            ->assertRedirect();
        return (int) DB::table('bablys_navigation_menus')->where('menu_key', $key)->value('id');
    }

    /** @return array{id: string, label: string, kind: string, url: string, parent_id: ?string, new_tab: bool} */
    private function item(string $label, string $url = '/tours', ?string $parent = null, string $kind = 'internal', bool $newTab = false): array
    {
        return [
            'id' => (string) Str::uuid(), 'label' => $label, 'kind' => $kind,
            'url' => $url, 'parent_id' => $parent, 'new_tab' => $newTab,
        ];
    }

    /** @param list<array<string, mixed>> $items
     *  @return array<string, mixed>
     */
    private function payload(array $items, int $version = 0, string $name = 'Main Header'): array
    {
        return ['expected_version' => $version, 'name' => $name, 'items' => $items];
    }

    public function test_guests_and_customers_cannot_read_or_mutate_menu_drafts(): void
    {
        $this->get('/admin/navigation-menus')->assertRedirect(route('login'));
        $this->post('/admin/navigation-menus', ['menu_key' => 'main-header', 'name' => 'Header'])
            ->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
        $this->get('/admin/navigation-menus')->assertForbidden();
        $this->post('/admin/navigation-menus', ['menu_key' => 'main-header', 'name' => 'Header'])
            ->assertForbidden();
        self::assertSame(0, DB::table('bablys_navigation_menus')->count());
    }

    public function test_staff_without_settings_permission_cannot_manage_navigation(): void
    {
        $this->actingAs($this->staff('content_editor'));
        $this->get('/admin/navigation-menus')->assertForbidden();
        $this->post('/admin/navigation-menus', ['menu_key' => 'main-header', 'name' => 'Header'])
            ->assertForbidden();
    }

    public function test_menu_drafts_are_private_and_public_api_starts_empty(): void
    {
        $this->actingAs($this->staff());
        $id = $this->createMenu();
        $this->get('/admin/navigation-menus')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn ($page) => $page->component('admin/navigation-menus/index'));
        $this->get('/admin/navigation-menus/'.$id)->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn ($page) => $page->component('admin/navigation-menus/show')
                ->where('menu.draft_version', 0)
                ->where('menu.published_version', 0)
                ->where('menu.can_publish', false));
        $response = $this->getJson('/api/public/navigation-menus')->assertOk();
        self::assertSame('{"menus":{}}', $response->getContent());
    }

    public function test_can_create_multiple_menus_and_reject_duplicate_keys(): void
    {
        $this->actingAs($this->staff());
        $this->createMenu('main-header', 'Main Header');
        $this->createMenu('footer-links', 'Footer Links');
        self::assertSame(2, DB::table('bablys_navigation_menus')->count());
        $this->post('/admin/navigation-menus', ['menu_key' => 'main-header', 'name' => 'Again'])
            ->assertSessionHasErrors('menu_key');
    }

    public function test_empty_menu_draft_can_be_saved_without_publishing(): void
    {
        $this->actingAs($this->staff());
        $id = $this->createMenu();
        $this->put('/admin/navigation-menus/'.$id, $this->payload([]))->assertRedirect();
        self::assertSame(1, (int) DB::table('bablys_navigation_menus')->where('id', $id)->value('draft_version'));
        $response = $this->getJson('/api/public/navigation-menus')->assertOk();
        self::assertSame('{"menus":{}}', $response->getContent());
    }

    public function test_saving_nested_items_preserves_order_and_revision_history(): void
    {
        $this->actingAs($this->staff());
        $id = $this->createMenu();
        $root = $this->item('Tours', '/tours');
        $child = $this->item('Destinations', '/destinations', $root['id']);
        $grandChild = $this->item('Cuba Tours', '/tours?destination=cuba', $child['id']);
        $this->put('/admin/navigation-menus/'.$id, $this->payload([$root, $child, $grandChild]))->assertRedirect();
        $items = json_decode((string) DB::table('bablys_navigation_menus')->where('id', $id)->value('draft_items'), true);
        self::assertSame(['Tours', 'Destinations', 'Cuba Tours'], array_column($items, 'label'));
        self::assertSame($root['id'], $items[1]['parent_id']);
        self::assertSame($child['id'], $items[2]['parent_id']);
        self::assertSame(1, (int) DB::table('bablys_navigation_menus')->where('id', $id)->value('draft_version'));
        self::assertSame(1, DB::table('bablys_navigation_menu_revisions')->count());
        $this->put('/admin/navigation-menus/'.$id, $this->payload([$root, $grandChild, $child], 1, 'Changed Name'))->assertRedirect();
        self::assertSame(2, DB::table('bablys_navigation_menu_revisions')->count());
        self::assertSame('Changed Name', DB::table('bablys_navigation_menus')->where('id', $id)->value('draft_name'));
        $response = $this->getJson('/api/public/navigation-menus')->assertOk();
        self::assertSame('{"menus":{}}', $response->getContent());
    }

    public function test_external_https_links_can_open_new_tab_and_unsafe_urls_are_blocked(): void
    {
        $this->actingAs($this->staff());
        $id = $this->createMenu();
        $safe = $this->item('Partner', 'https://partner.example.com/travel', null, 'external', true);
        $this->put('/admin/navigation-menus/'.$id, $this->payload([$safe]))->assertRedirect();
        $unsafe = $this->item('Bad', 'javascript:alert(1)', null, 'external', true);
        $this->put('/admin/navigation-menus/'.$id, $this->payload([$unsafe], 1))->assertSessionHasErrors('items.0.url');
        $protocolRelative = $this->item('Bad', '//evil.example.com/path');
        $this->put('/admin/navigation-menus/'.$id, $this->payload([$protocolRelative], 1))->assertSessionHasErrors('items.0.url');
        $credentials = $this->item('Bad', 'https://user:secret@evil.example.com', null, 'external');
        $this->put('/admin/navigation-menus/'.$id, $this->payload([$credentials], 1))->assertSessionHasErrors('items.0.url');
        self::assertSame(1, DB::table('bablys_navigation_menu_revisions')->count());
    }

    public function test_duplicate_item_identifiers_and_self_parent_are_rejected(): void
    {
        $this->actingAs($this->staff());
        $id = $this->createMenu();
        $first = $this->item('First');
        $second = $first;
        $second['label'] = 'Second';
        $this->put('/admin/navigation-menus/'.$id, $this->payload([$first, $second]))
            ->assertSessionHasErrors('items.1.id');
        $first['parent_id'] = $first['id'];
        $this->put('/admin/navigation-menus/'.$id, $this->payload([$first]))
            ->assertSessionHasErrors('items');
    }

    public function test_circular_parent_nesting_is_rejected_without_revision_write(): void
    {
        $this->actingAs($this->staff());
        $id = $this->createMenu();
        $one = $this->item('One');
        $two = $this->item('Two');
        $one['parent_id'] = $two['id'];
        $two['parent_id'] = $one['id'];
        $this->put('/admin/navigation-menus/'.$id, $this->payload([$one, $two]))->assertSessionHasErrors('items');
        self::assertSame(0, DB::table('bablys_navigation_menu_revisions')->count());
    }

    public function test_more_than_three_levels_or_orphan_parent_is_rejected(): void
    {
        $this->actingAs($this->staff());
        $id = $this->createMenu();
        $one = $this->item('One');
        $two = $this->item('Two', '/two', $one['id']);
        $three = $this->item('Three', '/three', $two['id']);
        $four = $this->item('Four', '/four', $three['id']);
        $this->put('/admin/navigation-menus/'.$id, $this->payload([$one, $two, $three, $four]))
            ->assertSessionHasErrors('items');
        $orphan = $this->item('Orphan', '/orphan', (string) Str::uuid());
        $this->put('/admin/navigation-menus/'.$id, $this->payload([$orphan]))->assertSessionHasErrors('items');
    }

    public function test_stale_version_does_not_overwrite_or_create_extra_revision(): void
    {
        $this->actingAs($this->staff());
        $id = $this->createMenu();
        $this->put('/admin/navigation-menus/'.$id, $this->payload([$this->item('First')]))->assertRedirect();
        $this->put('/admin/navigation-menus/'.$id, $this->payload([$this->item('Stale')]))
            ->assertSessionHasErrors('expected_version');
        self::assertSame(1, DB::table('bablys_navigation_menu_revisions')->count());
    }

    public function test_only_unpublished_menus_can_be_deleted(): void
    {
        $this->actingAs($this->staff());
        $id = $this->createMenu('footer-links', 'Footer Links');
        $this->put('/admin/navigation-menus/'.$id, $this->payload([$this->item('About', '/about')]))->assertRedirect();
        $this->delete('/admin/navigation-menus/'.$id, ['expected_version' => 0])
            ->assertSessionHasErrors('expected_version');
        $this->delete('/admin/navigation-menus/'.$id, ['expected_version' => 1])->assertRedirect();
        self::assertSame(0, DB::table('bablys_navigation_menus')->count());
        self::assertSame(0, DB::table('bablys_navigation_menu_revisions')->count());
    }

    public function test_published_menu_cannot_be_deleted_even_if_draft_has_changed(): void
    {
        $this->actingAs($this->staff());
        $id = $this->createMenu();
        DB::table('bablys_navigation_menus')->where('id', $id)->update([
            'published_name' => 'Original Live Menu',
            'published_items' => '[]',
            'published_version' => 1,
        ]);
        $this->delete('/admin/navigation-menus/'.$id, ['expected_version' => 0])
            ->assertSessionHasErrors('menu');
        self::assertSame(1, DB::table('bablys_navigation_menus')->count());
    }

    public function test_public_api_never_exposes_drafts_or_editor_fields(): void
    {
        $this->actingAs($this->staff());
        $id = $this->createMenu();
        $private = $this->item('UNPUBLISHED SECRET', '/private');
        $this->put('/admin/navigation-menus/'.$id, $this->payload([$private]))->assertRedirect();
        // Simulate a P4A.3-published snapshot directly; there is intentionally no publish route yet.
        $public = $this->item('Public Tours', '/tours');
        DB::table('bablys_navigation_menus')->where('id', $id)->update([
            'published_name' => 'Public Header',
            'published_items' => json_encode([$public], JSON_THROW_ON_ERROR),
            'published_version' => 1,
        ]);
        $this->get('/api/public/navigation-menus')->assertOk()->assertJsonPath('menus.main-header.name', 'Public Header')
            ->assertJsonPath('menus.main-header.items.0.label', 'Public Tours')
            ->assertDontSee('UNPUBLISHED SECRET')->assertDontSee('draft_version')
            ->assertDontSee('draft_items')->assertDontSee('edited_by');
    }

    public function test_route_permissions_http_methods_and_no_publish_route_yet(): void
    {
        foreach (['admin.navigation-menus.index', 'admin.navigation-menus.store',
            'admin.navigation-menus.show', 'admin.navigation-menus.update',
            'admin.navigation-menus.destroy'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, $name);
            $middleware = implode('|', $route->gatherMiddleware());
            self::assertStringContainsString('EnsureCmsStaff', $middleware);
            self::assertStringContainsString('settings.manage', $middleware);
        }
        self::assertSame(['PUT'], Route::getRoutes()->getByName('admin.navigation-menus.update')?->methods());
        self::assertSame(['DELETE'], Route::getRoutes()->getByName('admin.navigation-menus.destroy')?->methods());
        self::assertNull(Route::getRoutes()->getByName('admin.navigation-menus.publish'));
    }

    public function test_admin_interface_reuses_existing_layout_and_link_validation_contracts(): void
    {
        $index = (string) file_get_contents(base_path('resources/js/pages/admin/navigation-menus/index.tsx'));
        $show = (string) file_get_contents(base_path('resources/js/pages/admin/navigation-menus/show.tsx'));
        self::assertStringContainsString('<BablysAdminLayout>', $index);
        self::assertStringContainsString('<BablysAdminLayout>', $show);
        self::assertStringNotContainsString('<AppSidebar', $show);
        self::assertStringContainsString('Save private menu draft', $show);
        self::assertStringContainsString('Add child', $show);
        self::assertStringContainsString('new_tab', $show);
    }
}
