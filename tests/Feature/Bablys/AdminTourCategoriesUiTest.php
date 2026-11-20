<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsRole;
use App\Models\Travel\TourCategory;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AdminTourCategoriesUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/tour-categories')->assertRedirect(route('login'));
    }

    public function test_registered_customer_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->get('/admin/tour-categories')->assertForbidden();
    }

    public function test_unrelated_staff_role_cannot_open_editor(): void
    {
        $this->actingAs($this->withRole('review_moderator'))
            ->get('/admin/tour-categories')->assertForbidden();
    }

    public function test_authorized_editor_receives_the_correct_inertia_page(): void
    {
        $this->actingAs($this->withRole('content_editor'))
            ->get('/admin/tour-categories')->assertOk()->assertInertia(
                fn (Assert $page) => $page->component('admin/tour-categories/index')
            );
    }

    public function test_suspended_editor_is_blocked_from_page_and_json_api(): void
    {
        $editor = $this->withRole('content_editor');
        $editor->forceFill(['cms_staff_disabled_at' => now()])->save();

        $this->actingAs($editor)->get('/admin/tour-categories')->assertForbidden();
        $this->actingAs($editor)->getJson('/admin/internal/tour-categories')->assertForbidden();
    }

    public function test_editor_can_use_existing_category_api_without_new_migrations(): void
    {
        $editor = $this->withRole('content_editor');
        TourCategory::query()->create(['name' => 'Bangladesh Tours', 'slug' => 'bangladesh-tours']);
        $this->actingAs($editor)->getJson('/admin/internal/tour-categories')
            ->assertOk()->assertJsonPath('data.0.slug', 'bangladesh-tours');
        $this->actingAs($editor)->getJson('/admin/internal/tour-categories/options')
            ->assertOk()->assertJsonPath('data.0.name', 'Bangladesh Tours');
    }

    private function withRole(string $roleKey): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        CmsRole::query()->where('key', $roleKey)->firstOrFail()->users()->attach($user->getKey());

        return $user;
    }
}
