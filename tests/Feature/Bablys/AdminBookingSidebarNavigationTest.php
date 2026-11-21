<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsPermission;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** P3H.5 follow-up: permission-filtered native Tour Management submenu. */
final class AdminBookingSidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $environment = (string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? ''));
        $driver = (string) (getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? ''));
        $database = (string) (getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? ''));
        if ($environment !== 'testing' || $driver !== 'mysql'
            || preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*_test\z/D', $database) !== 1
            || str_ends_with($database, 'race_test')) {
            throw new \RuntimeException('Navigation tests require APP_ENV=testing and a dedicated non-race MySQL *_test database.');
        }

        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_authorized_staff_receives_booking_links_nested_inside_tour_management(): void
    {
        $user = $this->editorWithPermissions(['tours.view', 'tours.edit', 'tours.publish']);
        $response = $this->actingAs($user)->getJson('/admin/internal/navigation')->assertOk();
        $navigation = $response->json();
        self::assertIsArray($navigation);
        $group = $this->findTourManagement($navigation);
        self::assertNotNull($group, 'Tour management group should be present');
        self::assertSame('Tour management', $group['title'] ?? null);
        $links = $group['links'] ?? null;
        self::assertIsArray($links);
        self::assertContains('/admin/tours', array_column($links, 'href'));
        self::assertContains('/admin/booking-requests', array_column($links, 'href'));
        self::assertContains('/admin/bookings', array_column($links, 'href'));
    }

    public function test_staff_with_tour_view_and_edit_but_no_publish_does_not_see_booking_links(): void
    {
        $user = $this->editorWithPermissions(['tours.view', 'tours.edit']);
        $response = $this->actingAs($user)->getJson('/admin/internal/navigation')->assertOk();
        $response->assertJsonFragment(['href' => '/admin/tours']);
        $response->assertJsonMissing(['href' => '/admin/bookings']);
        $response->assertJsonMissing(['href' => '/admin/booking-requests']);
    }

    public function test_staff_with_tour_view_and_publish_but_no_edit_does_not_see_booking_links(): void
    {
        $user = $this->editorWithPermissions(['tours.view', 'tours.publish']);
        $response = $this->actingAs($user)->getJson('/admin/internal/navigation')->assertOk();
        $response->assertJsonFragment(['href' => '/admin/tours']);
        $response->assertJsonMissing(['href' => '/admin/bookings']);
        $response->assertJsonMissing(['href' => '/admin/booking-requests']);
    }

    public function test_bookings_links_do_not_relax_existing_booking_route_middleware(): void
    {
        foreach (['admin.bookings.index', 'admin.bookings.show', 'admin.booking-requests.index'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, $name);
            $middleware = implode('|', $route->gatherMiddleware());
            self::assertStringContainsString('EnsureCmsStaff', $middleware);
            self::assertStringContainsString('tours.edit', $middleware);
            self::assertStringContainsString('tours.publish', $middleware);
        }
    }

    /**
     * @param list<string> $granted
     */
    private function editorWithPermissions(array $granted): User
    {
        $role = CmsRole::query()->where('key', 'content_editor')->firstOrFail();
        $permissions = CmsPermission::query()->whereIn('key', ['tours.view', 'tours.edit', 'tours.publish'])->get();
        foreach ($permissions as $permission) {
            if (in_array((string) $permission->key, $granted, true)) {
                $role->permissions()->syncWithoutDetaching([$permission->getKey()]);
            } else {
                $role->permissions()->detach($permission->getKey());
            }
        }
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role->users()->attach($user->getKey());
        return $user;
    }

    /**
     * @param array<array-key, mixed> $node
     * @return array<array-key, mixed>|null
     */
    private function findTourManagement(array $node): ?array
    {
        if (($node['key'] ?? null) === 'tour-packages') {
            return $node;
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $found = $this->findTourManagement($child);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }
}
