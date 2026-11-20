<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Taxonomy\PublishedTaxonomyCatalog;
use App\Models\Cms\CmsRole;
use App\Models\Travel\Destination;
use App\Models\Travel\TourCategory;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Cross-module validation, publication and audit-preservation regressions. */
final class CmsTaxonomyIntegrityRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_published_child_is_rejected_under_draft_parent_without_partial_write(): void
    {
        $this->actingAs($this->editor());
        $parent = $this->postJson('/admin/internal/tour-categories', $this->category('draft-parent'))
            ->assertCreated();
        $this->postJson('/admin/internal/tour-categories', [
            ...$this->category('published-child'),
            'parent_id' => $parent->json('data.id'),
            'status' => 'published',
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->assertDatabaseMissing('travel_tour_categories', ['slug' => 'published-child']);
        self::assertSame(1, DB::table('cms_audit_events')->count());
    }

    public function test_circular_reparenting_does_not_change_tree_or_add_audit(): void
    {
        $this->actingAs($this->editor());
        $parent = $this->postJson('/admin/internal/tour-categories', $this->category('parent-a'))
            ->assertCreated();
        $child = $this->postJson('/admin/internal/tour-categories', [
            ...$this->category('child-a'), 'parent_id' => $parent->json('data.id'),
        ])->assertCreated();
        $this->putJson('/admin/internal/tour-categories/'.$parent->json('data.id'), [
            'parent_id' => $child->json('data.id'),
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        self::assertNull(TourCategory::query()->findOrFail((int) $parent->json('data.id'))->parent_id);
        self::assertSame(2, DB::table('cms_audit_events')->count());
    }

    public function test_slug_history_remains_reserved_after_soft_deletion_for_both_taxonomies(): void
    {
        $this->actingAs($this->editor());
        $category = $this->postJson('/admin/internal/tour-categories', $this->category('old-slug'))
            ->assertCreated();
        $destination = $this->postJson('/admin/internal/destinations', $this->destination('old-place'))
            ->assertCreated();
        $this->deleteJson('/admin/internal/tour-categories/'.$category->json('data.id'))->assertNoContent();
        $this->deleteJson('/admin/internal/destinations/'.$destination->json('data.id'))->assertNoContent();
        $this->postJson('/admin/internal/tour-categories', $this->category('old-slug'))
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->postJson('/admin/internal/destinations', $this->destination('old-place'))
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        self::assertNotNull(TourCategory::withTrashed()->where('slug', 'old-slug')->firstOrFail()->deleted_at);
        self::assertNotNull(Destination::withTrashed()->where('slug', 'old-place')->firstOrFail()->deleted_at);
    }

    public function test_invalid_destination_coordinates_do_not_modify_row_or_audit_history(): void
    {
        $this->actingAs($this->editor());
        $destination = $this->postJson('/admin/internal/destinations', $this->destination('seaside'))
            ->assertCreated();
        $id = (int) $destination->json('data.id');
        $this->putJson('/admin/internal/destinations/'.$id, [
            'latitude' => 21.4272,
        ])->assertUnprocessable()->assertJsonValidationErrors('longitude');
        $this->putJson('/admin/internal/destinations/'.$id, [
            'latitude' => 95, 'longitude' => 92.0058,
        ])->assertUnprocessable()->assertJsonValidationErrors('latitude');
        self::assertNull(Destination::query()->findOrFail($id)->latitude);
        self::assertSame(1, DB::table('cms_audit_events')->count());
    }

    public function test_published_destination_requires_archive_before_deletion_and_exits_public_catalog(): void
    {
        $this->actingAs($this->editor());
        $destination = $this->postJson('/admin/internal/destinations', [
            ...$this->destination('published-place'), 'status' => 'published',
        ])->assertCreated();
        $id = (int) $destination->json('data.id');
        self::assertSame(['published-place'], array_column(app(PublishedTaxonomyCatalog::class)->destinations('BD'), 'slug'));
        $this->deleteJson('/admin/internal/destinations/'.$id)
            ->assertUnprocessable()->assertJsonValidationErrors('destination');
        $this->putJson('/admin/internal/destinations/'.$id, ['status' => 'archived'])->assertOk();
        $this->deleteJson('/admin/internal/destinations/'.$id)->assertNoContent();
        self::assertSame([], app(PublishedTaxonomyCatalog::class)->destinations('BD'));
        $this->assertDatabaseHas('cms_audit_events', ['action' => 'taxonomy.destination.deleted']);
    }

    public function test_no_op_update_of_both_taxonomies_does_not_create_extra_audit_events(): void
    {
        $this->actingAs($this->editor());
        $category = $this->postJson('/admin/internal/tour-categories', $this->category('steady-category'))
            ->assertCreated();
        $destination = $this->postJson('/admin/internal/destinations', $this->destination('steady-place'))
            ->assertCreated();
        $this->putJson('/admin/internal/tour-categories/'.$category->json('data.id'), [
            'name' => 'Tour steady-category',
        ])->assertOk();
        $this->putJson('/admin/internal/destinations/'.$destination->json('data.id'), [
            'name' => 'Destination steady-place',
        ])->assertOk();
        self::assertSame(2, DB::table('cms_audit_events')->count());
    }

    /** @return array{name:string,slug:string,status:string} */
    private function category(string $slug): array
    {
        return ['name' => 'Tour '.$slug, 'slug' => $slug, 'status' => 'draft'];
    }

    /** @return array{name:string,slug:string,country_code:string,status:string} */
    private function destination(string $slug): array
    {
        return [
            'name' => 'Destination '.$slug, 'slug' => $slug,
            'country_code' => 'BD', 'status' => 'draft',
        ];
    }

    private function editor(): User
    {
        $editor = User::factory()->create(['email_verified_at' => now()]);
        CmsRole::query()->where('key', 'content_editor')->firstOrFail()->users()->attach($editor->getKey());

        return $editor;
    }
}
