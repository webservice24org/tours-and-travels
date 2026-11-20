<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Models\Cms\CmsRole;
use App\Models\Travel\Destination;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AdminDestinationsUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/destinations')->assertRedirect(route('login'));
    }

    public function test_registered_customer_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->get('/admin/destinations')->assertForbidden();
    }

    public function test_unrelated_staff_role_cannot_open_destinations_ui(): void
    {
        $this->actingAs($this->withRole('review_moderator'))
            ->get('/admin/destinations')->assertForbidden();
    }

    public function test_authorized_content_editor_receives_destination_inertia_page(): void
    {
        $this->actingAs($this->withRole('content_editor'))
            ->get('/admin/destinations')->assertOk()->assertInertia(
                fn (Assert $page) => $page->component('admin/destinations/index')
            );
    }

    public function test_unverified_staff_cannot_use_ui(): void
    {
        $editor = $this->withRole('content_editor');
        $editor->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($editor)->get('/admin/destinations')->assertForbidden();
    }

    public function test_suspended_editor_cannot_open_ui_or_destination_api(): void
    {
        $editor = $this->withRole('content_editor');
        $editor->forceFill(['cms_staff_disabled_at' => now()])->save();

        $this->actingAs($editor)->get('/admin/destinations')->assertForbidden();
        $this->actingAs($editor)->getJson('/admin/internal/destinations')->assertForbidden();
    }

    public function test_ui_uses_existing_destination_api_with_filters_and_precise_coordinate_values(): void
    {
        $editor = $this->withRole('content_editor');
        Destination::query()->create([
            'name' => 'Coxs Bazar', 'slug' => 'coxs-bazar', 'country_code' => 'BD',
            'city_name' => 'Coxs Bazar', 'latitude' => '21.4272000',
            'longitude' => '92.0058000', 'status' => PublicationStatus::Published,
        ]);
        Destination::query()->create([
            'name' => 'Phuket', 'slug' => 'phuket', 'country_code' => 'TH',
            'status' => PublicationStatus::Draft,
        ]);

        $this->actingAs($editor)
            ->getJson('/admin/internal/destinations?status=published&country_code=BD&has_coordinates=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'coxs-bazar')
            ->assertJsonPath('data.0.latitude', '21.4272000')
            ->assertJsonPath('data.0.longitude', '92.0058000');
    }

    public function test_editor_can_create_destination_with_existing_backend_and_audit(): void
    {
        $editor = $this->withRole('content_editor');
        $response = $this->actingAs($editor)->postJson('/admin/internal/destinations', [
            'name' => 'Sylhet', 'slug' => 'sylhet', 'country_code' => 'BD',
            'region_name' => 'Sylhet', 'status' => 'draft',
        ])->assertCreated()->assertJsonPath('data.slug', 'sylhet');

        $this->assertDatabaseHas('cms_audit_events', [
            'action' => 'taxonomy.destination.created',
            'subject_type' => 'destination',
            'subject_id' => (string) $response->json('data.id'),
            'actor_id' => $editor->getKey(),
        ]);
    }

    public function test_ui_route_has_auth_and_permission_middleware(): void
    {
        $route = Route::getRoutes()->getByName('admin.destinations.index');
        self::assertNotNull($route);
        self::assertSame('admin/destinations', $route->uri());
        $middleware = implode('|', $route->gatherMiddleware());
        self::assertStringContainsString('auth', $middleware);
        self::assertStringContainsString('EnsureCmsStaff', $middleware);
        self::assertStringContainsString('taxonomy.manage', $middleware);
    }

    private function withRole(string $roleKey): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        CmsRole::query()->where('key', $roleKey)->firstOrFail()->users()->attach($user->getKey());

        return $user;
    }
}
