<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsRole;
use App\Models\Travel\Destination;
use App\Models\Travel\Tour;
use App\Models\Travel\TourCategory;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AdminTourManagementBackendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_guests_customers_and_reviewers_cannot_mutate_tours(): void
    {
        $this->getJson('/admin/internal/tours')->assertUnauthorized();
        $this->postJson('/admin/internal/tours', $this->payload())->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->getJson('/admin/internal/tours')->assertForbidden();
        $this->actingAs($this->withRole('review_moderator'))
            ->postJson('/admin/internal/tours', $this->payload())->assertForbidden();
        $this->assertDatabaseMissing('travel_tours', ['slug' => 'sample-adventure']);
    }

    public function test_editor_creates_draft_with_taxonomy_links_and_audit(): void
    {
        $editor = $this->withRole('content_editor');
        $category = $this->category('domestic');
        $destination = $this->destination('dhaka');
        $payload = $this->payload();
        $payload['category_ids'] = [$category->id];
        $payload['destination_ids'] = [$destination->id];
        $payload['slug'] = ' SAMPLE-ADVENTURE ';
        $response = $this->actingAs($editor)->postJson('/admin/internal/tours', $payload)
            ->assertCreated()->assertJsonPath('data.slug', 'sample-adventure')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.category_ids.0', $category->id);
        $id = (int) $response->json('data.id');
        $this->assertDatabaseHas('travel_tour_category', ['tour_id' => $id, 'category_id' => $category->id]);
        $this->assertDatabaseHas('travel_tour_destination', ['tour_id' => $id, 'destination_id' => $destination->id]);
        $this->assertDatabaseHas('cms_audit_events', [
            'action' => 'tour.created', 'actor_id' => $editor->getKey(),
            'subject_type' => 'tour', 'subject_id' => (string) $id,
        ]);
    }

    public function test_editor_cannot_publish_or_schedule_without_separate_permission(): void
    {
        $editor = $this->withRole('content_editor');
        $payload = $this->payload();
        $payload['status'] = 'published';
        $this->actingAs($editor)->postJson('/admin/internal/tours', $payload)->assertForbidden();
        $payload['status'] = 'scheduled';
        $payload['scheduled_for'] = now()->addDays(3)->toIso8601String();
        $this->actingAs($editor)->postJson('/admin/internal/tours', $payload)->assertForbidden();
        self::assertSame(0, Tour::query()->count());
    }

    public function test_administrator_publish_requires_published_category_and_destination(): void
    {
        $admin = $this->withRole('administrator');
        $category = $this->category('domestic');
        $destination = $this->destination('dhaka');
        $payload = $this->payload();
        $payload['status'] = 'published';
        $payload['category_ids'] = [$category->id];
        $payload['destination_ids'] = [$destination->id];
        $this->actingAs($admin)->postJson('/admin/internal/tours', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('category_ids');
        self::assertSame(0, Tour::query()->count());
        self::assertSame(0, DB::table('cms_audit_events')->count());
    }

    public function test_administrator_can_publish_ready_tour_and_set_timestamp(): void
    {
        $admin = $this->withRole('administrator');
        $category = $this->category('domestic', 'published');
        $destination = $this->destination('dhaka', 'published');
        $payload = $this->payload();
        $payload['status'] = 'published';
        $payload['category_ids'] = [$category->id];
        $payload['destination_ids'] = [$destination->id];
        $this->actingAs($admin)->postJson('/admin/internal/tours', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'published');
        $tour = Tour::query()->where('slug', 'sample-adventure')->firstOrFail();
        self::assertNotNull($tour->published_at);
        self::assertNull($tour->scheduled_for);
    }

    public function test_scheduled_tour_requires_future_time_and_stays_private(): void
    {
        $admin = $this->withRole('administrator');
        $payload = $this->payload();
        $payload['status'] = 'scheduled';
        $this->actingAs($admin)->postJson('/admin/internal/tours', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_for');
        $payload['scheduled_for'] = now()->subDay()->toIso8601String();
        $this->actingAs($admin)->postJson('/admin/internal/tours', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_for');
        $payload['scheduled_for'] = now()->addDay()->toIso8601String();
        $this->actingAs($admin)->postJson('/admin/internal/tours', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'scheduled');
        $tour = Tour::query()->firstOrFail();
        self::assertNull($tour->published_at);
        self::assertSame(0, Tour::query()->publiclyAvailable()->count());
    }

    public function test_editing_tour_preserves_slug_and_blocks_deleted_slug_reuse(): void
    {
        $editor = $this->withRole('content_editor');
        $tour = $this->tour();
        $this->actingAs($editor)->putJson('/admin/internal/tours/'.$tour->id, [
            'slug' => 'new-slug',
        ])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $tour->delete();
        $this->actingAs($editor)->postJson('/admin/internal/tours', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_bad_taxonomy_assignments_are_rejected_and_rolled_back(): void
    {
        $editor = $this->withRole('content_editor');
        $category = $this->category('domestic');
        $payload = $this->payload();
        $payload['category_ids'] = [$category->id, $category->id];
        $this->actingAs($editor)->postJson('/admin/internal/tours', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('category_ids.1');
        $payload['category_ids'] = [999999];
        $this->actingAs($editor)->postJson('/admin/internal/tours', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('category_ids.0');
        self::assertSame(0, Tour::query()->count());
    }

    public function test_partial_update_and_no_op_do_not_duplicate_audit(): void
    {
        $editor = $this->withRole('content_editor');
        $tour = $this->tour();
        $url = '/admin/internal/tours/'.$tour->id;
        $this->actingAs($editor)->putJson($url, [
            'title' => 'Changed Title', 'description' => 'Confidential CMS body',
        ])->assertOk()->assertJsonPath('data.title', 'Changed Title');
        $count = DB::table('cms_audit_events')->count();
        self::assertSame(1, $count);
        $this->actingAs($editor)->putJson($url, [
            'title' => 'Changed Title', 'description' => 'Confidential CMS body',
        ])->assertOk();
        self::assertSame($count, DB::table('cms_audit_events')->count());
        $audit = (string) DB::table('cms_audit_events')->value('metadata_json');
        self::assertStringNotContainsString('Confidential CMS body', $audit);
    }

    public function test_taxonomy_updates_are_audited_and_empty_arrays_clear_links(): void
    {
        $editor = $this->withRole('content_editor');
        $tour = $this->tour();
        $category = $this->category('domestic');
        $url = '/admin/internal/tours/'.$tour->id;
        $this->actingAs($editor)->putJson($url, [
            'category_ids' => [$category->id], 'destination_ids' => [],
        ])->assertOk()->assertJsonPath('data.category_ids.0', $category->id);
        $this->assertDatabaseHas('cms_audit_events', ['action' => 'tour.taxonomy.changed']);
        $this->actingAs($editor)->putJson($url, ['category_ids' => []])
            ->assertOk()->assertJsonPath('data.category_ids', []);
        self::assertSame(0, DB::table('travel_tour_category')->count());
    }

    public function test_editor_cannot_modify_existing_published_tour(): void
    {
        $editor = $this->withRole('content_editor');
        $tour = $this->tour(['status' => 'published', 'published_at' => now()]);
        $this->actingAs($editor)->putJson('/admin/internal/tours/'.$tour->id, [
            'title' => 'Unauthorized',
        ])->assertForbidden();
        self::assertSame('Sample Adventure', $tour->refresh()->title);
    }

    public function test_soft_delete_blocks_live_tour_and_audits_draft_deletion(): void
    {
        $admin = $this->withRole('administrator');
        $published = $this->tour(['status' => 'published', 'published_at' => now()]);
        $this->actingAs($admin)->deleteJson('/admin/internal/tours/'.$published->id)
            ->assertUnprocessable()->assertJsonValidationErrors('tour');
        $draft = Tour::query()->create(['title' => 'Draft Trip', 'slug' => 'draft-trip']);
        $this->actingAs($admin)->deleteJson('/admin/internal/tours/'.$draft->id)->assertNoContent();
        $this->actingAs($admin)->getJson('/admin/internal/tours/'.$draft->id)->assertNotFound();
        $this->assertDatabaseHas('cms_audit_events', [
            'action' => 'tour.deleted', 'subject_id' => (string) $draft->id,
        ]);
    }

    public function test_listing_filters_pagination_and_taxonomy_options(): void
    {
        $editor = $this->withRole('content_editor');
        $this->tour();
        Tour::query()->create(['title' => 'Hidden Beach', 'slug' => 'hidden-beach', 'is_featured' => true]);
        $category = $this->category('domestic');
        $destination = $this->destination('dhaka');
        $this->actingAs($editor)->getJson('/admin/internal/tours?search=Sample&status=draft&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'sample-adventure');
        $this->actingAs($editor)->getJson('/admin/internal/tours?featured=1')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($editor)->getJson('/admin/internal/tours?per_page=100')->assertUnprocessable();
        $this->actingAs($editor)->getJson('/admin/internal/tours/taxonomy-options')
            ->assertOk()->assertJsonPath('categories.0.id', $category->id)
            ->assertJsonPath('destinations.0.id', $destination->id);
    }

    public function test_suspended_editor_is_blocked_immediately(): void
    {
        $editor = $this->withRole('content_editor');
        $this->actingAs($editor)->postJson('/admin/internal/tours', $this->payload())->assertCreated();
        $editor->forceFill(['cms_staff_disabled_at' => now()])->save();
        $this->actingAs($editor)->getJson('/admin/internal/tours')->assertForbidden();
        $payload = $this->payload();
        $payload['slug'] = 'another-tour';
        $this->actingAs($editor)->postJson('/admin/internal/tours', $payload)->assertForbidden();
    }

    private function withRole(string $key): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        CmsRole::query()->where('key', $key)->firstOrFail()->users()->attach($user->getKey());
        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'title' => 'Sample Adventure', 'slug' => 'sample-adventure',
            'summary' => 'Sample tour summary', 'status' => 'draft',
            'duration_days' => 3, 'duration_nights' => 2,
            'is_featured' => false, 'sort_order' => 1,
        ];
    }

    /** @param array<string, mixed> $values */
    private function tour(array $values = []): Tour
    {
        return Tour::query()->create(array_merge([
            'title' => 'Sample Adventure', 'slug' => 'sample-adventure',
        ], $values));
    }

    private function category(string $slug, string $status = 'draft'): TourCategory
    {
        return TourCategory::query()->create([
            'name' => 'Test Category', 'slug' => $slug, 'status' => $status,
        ]);
    }

    private function destination(string $slug, string $status = 'draft'): Destination
    {
        return Destination::query()->create([
            'name' => 'Test Destination', 'slug' => $slug, 'country_code' => 'BD',
            'status' => $status,
        ]);
    }
}
