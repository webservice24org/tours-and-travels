<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Domain\Travel\Taxonomy\PublishedTaxonomyCatalog;
use App\Models\Cms\CmsRole;
use App\Models\Travel\TourCategory;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class AdminTourCategoryCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_guests_customers_and_unrelated_staff_cannot_manage_categories(): void
    {
        $this->getJson('/admin/internal/tour-categories')->assertUnauthorized();
        $this->postJson('/admin/internal/tour-categories', $this->payload())->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->getJson('/admin/internal/tour-categories')->assertForbidden();

        $reviewer = $this->withRole('review_moderator');
        $this->actingAs($reviewer)
            ->postJson('/admin/internal/tour-categories', $this->payload())
            ->assertForbidden();
        $this->assertDatabaseMissing('travel_tour_categories', ['slug' => 'family-trips']);
    }

    public function test_authorized_editor_can_create_a_draft_and_audit_the_creation(): void
    {
        $editor = $this->withRole('content_editor');
        $data = $this->payload();
        $data['slug'] = '  FAMILY-TRIPS  ';
        $result = $this->actingAs($editor)->postJson('/admin/internal/tour-categories', $data)
            ->assertCreated()
            ->assertJsonPath('data.slug', 'family-trips')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.parent_id', null);

        $id = (int) $result->json('data.id');
        $this->assertDatabaseHas('travel_tour_categories', ['id' => $id, 'slug' => 'family-trips']);
        $this->assertDatabaseHas('cms_audit_events', [
            'actor_id' => $editor->getKey(),
            'action' => 'taxonomy.category.created',
            'subject_type' => 'tour_category',
            'subject_id' => (string) $id,
        ]);
    }

    public function test_category_listing_search_filters_and_options_are_paginated_and_private(): void
    {
        $editor = $this->withRole('content_editor');
        TourCategory::query()->create(['name' => 'Zanzibar', 'slug' => 'zanzibar']);
        TourCategory::query()->create(['name' => 'Bangladesh', 'slug' => 'bangladesh', 'status' => 'published']);
        TourCategory::query()->create(['name' => 'Archived', 'slug' => 'archived', 'status' => 'archived']);

        $this->actingAs($editor)->getJson('/admin/internal/tour-categories?search=Bang&status=published&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', 'bangladesh');
        $this->actingAs($editor)->getJson('/admin/internal/tour-categories/options')
            ->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($editor)->getJson('/admin/internal/tour-categories?per_page=100')
            ->assertUnprocessable();
    }

    public function test_duplicate_deleted_slug_and_reserved_slug_are_rejected(): void
    {
        $editor = $this->withRole('content_editor');
        TourCategory::query()->create(['name' => 'Removed', 'slug' => 'family-trips'])->delete();

        $this->actingAs($editor)->postJson('/admin/internal/tour-categories', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $data = $this->payload();
        $data['slug'] = 'admin';
        $this->actingAs($editor)->postJson('/admin/internal/tour-categories', $data)
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_invalid_status_and_nonexistent_parent_cannot_write_or_audit(): void
    {
        $editor = $this->withRole('content_editor');
        $data = $this->payload();
        $data['status'] = 'scheduled'; // No schedule timestamp/scheduler in P3B.1.
        $this->actingAs($editor)->postJson('/admin/internal/tour-categories', $data)
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $data = $this->payload();
        $data['parent_id'] = 12345;
        $this->actingAs($editor)->postJson('/admin/internal/tour-categories', $data)
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        self::assertSame(0, (int) DB::table('cms_audit_events')->count());
    }

    public function test_reparenting_rejects_self_and_descendant_cycles(): void
    {
        $editor = $this->withRole('content_editor');
        $root = TourCategory::query()->create(['name' => 'Root', 'slug' => 'root']);
        $child = TourCategory::query()->create(['name' => 'Child', 'slug' => 'child', 'parent_id' => $root->id]);
        $endpoint = '/admin/internal/tour-categories/'.$root->id;
        $this->actingAs($editor)->putJson($endpoint, ['parent_id' => $root->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->actingAs($editor)->putJson($endpoint, ['parent_id' => $child->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        self::assertNull($root->refresh()->parent_id);
        self::assertSame(0, (int) DB::table('cms_audit_events')->count());
    }

    public function test_published_child_requires_published_ancestors(): void
    {
        $editor = $this->withRole('content_editor');
        $parent = TourCategory::query()->create(['name' => 'Draft Parent', 'slug' => 'draft-parent']);
        $data = $this->payload();
        $data['parent_id'] = $parent->id;
        $data['status'] = 'published';
        $this->actingAs($editor)->postJson('/admin/internal/tour-categories', $data)
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->actingAs($editor)->putJson('/admin/internal/tour-categories/'.$parent->id, [
            'status' => 'published',
        ])->assertOk();
        $this->actingAs($editor)->postJson('/admin/internal/tour-categories', $data)
            ->assertCreated()->assertJsonPath('data.status', 'published');
    }

    public function test_published_parent_with_published_children_cannot_be_unpublished(): void
    {
        $editor = $this->withRole('content_editor');
        $parent = TourCategory::query()->create([
            'name' => 'Published Parent', 'slug' => 'published-parent', 'status' => 'published',
        ]);
        TourCategory::query()->create([
            'name' => 'Published Child', 'slug' => 'published-child', 'parent_id' => $parent->id,
            'status' => 'published',
        ]);
        $this->actingAs($editor)->putJson('/admin/internal/tour-categories/'.$parent->id, [
            'status' => 'archived',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        self::assertSame(PublicationStatus::Published, $parent->refresh()->status);
    }

    public function test_updates_are_partial_audited_and_no_op_does_not_write_an_event(): void
    {
        $editor = $this->withRole('content_editor');
        $category = TourCategory::query()->create(['name' => 'Original', 'slug' => 'original']);
        $url = '/admin/internal/tour-categories/'.$category->id;
        $this->actingAs($editor)->putJson($url, [
            'name' => 'New name', 'description' => 'Details should not enter audit metadata.',
        ])->assertOk()->assertJsonPath('data.name', 'New name');
        $this->assertDatabaseHas('cms_audit_events', ['action' => 'taxonomy.category.updated']);
        self::assertSame(1, (int) DB::table('cms_audit_events')->count());
        $metadata = (string) DB::table('cms_audit_events')->value('metadata_json');
        self::assertStringNotContainsString('Details should not', $metadata);
        $this->actingAs($editor)->putJson($url, ['name' => 'New name'])->assertOk();
        self::assertSame(1, (int) DB::table('cms_audit_events')->count());
    }

    public function test_existing_category_slug_is_immutable_until_redirects_exist(): void
    {
        $editor = $this->withRole('content_editor');
        $category = TourCategory::query()->create(['name' => 'Original', 'slug' => 'original']);
        $this->actingAs($editor)
            ->putJson('/admin/internal/tour-categories/'.$category->id, ['slug' => 'changed'])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        self::assertSame('original', $category->refresh()->slug);
    }

    public function test_delete_requires_empty_children_and_soft_deletes_with_an_audit_record(): void
    {
        $editor = $this->withRole('content_editor');
        $parent = TourCategory::query()->create(['name' => 'Parent', 'slug' => 'parent']);
        $child = TourCategory::query()->create(['name' => 'Child', 'slug' => 'child', 'parent_id' => $parent->id]);
        $this->actingAs($editor)->deleteJson('/admin/internal/tour-categories/'.$parent->id)
            ->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->actingAs($editor)->deleteJson('/admin/internal/tour-categories/'.$child->id)
            ->assertNoContent();
        $this->actingAs($editor)->getJson('/admin/internal/tour-categories/'.$child->id)->assertNotFound();
        self::assertNotNull(TourCategory::withTrashed()->findOrFail($child->id)->deleted_at);
        $this->assertDatabaseHas('cms_audit_events', [
            'action' => 'taxonomy.category.deleted', 'subject_id' => (string) $child->id,
        ]);
        $this->actingAs($editor)->deleteJson('/admin/internal/tour-categories/'.$parent->id)
            ->assertNoContent();
    }

    public function test_revoking_permission_or_suspending_staff_blocks_further_changes(): void
    {
        $staff = $this->withRole('content_editor');
        self::assertTrue(Gate::forUser($staff)->allows('taxonomy.manage'));
        $this->actingAs($staff)->postJson('/admin/internal/tour-categories', $this->payload())
            ->assertCreated();
        $staff->forceFill(['cms_staff_disabled_at' => now()])->save();
        self::assertFalse(Gate::forUser($staff)->allows('taxonomy.manage'));
        $data = $this->payload();
        $data['slug'] = 'another-trip';
        $this->actingAs($staff)->postJson('/admin/internal/tour-categories', $data)
            ->assertForbidden();
        $this->assertDatabaseMissing('travel_tour_categories', ['slug' => 'another-trip']);
    }

    public function test_public_catalog_still_excludes_nonpublished_categories(): void
    {
        TourCategory::query()->create(['name' => 'Public', 'slug' => 'public-tour', 'status' => 'published']);
        TourCategory::query()->create(['name' => 'Hidden', 'slug' => 'hidden-tour', 'status' => 'draft']);
        $published = app(PublishedTaxonomyCatalog::class)->topLevelCategories();
        self::assertSame(['public-tour'], array_column($published, 'slug'));
        $this->get('/')->assertOk();
    }

    /** @return array{name:string,slug:string,status:string,sort_order:int} */
    private function payload(): array
    {
        return [
            'name' => 'Family Trips',
            'slug' => 'family-trips',
            'status' => 'draft',
            'sort_order' => 10,
        ];
    }

    private function withRole(string $key): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = CmsRole::query()->where('key', $key)->firstOrFail();
        $role->users()->attach($user->getKey());

        return $user;
    }
}
