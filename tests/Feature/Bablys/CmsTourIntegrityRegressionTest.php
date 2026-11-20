<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Tours\PublishedTourCatalog;
use App\Models\Travel\Tour;
use App\Models\Travel\TourTag;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bablys\TourQaTestCase;

/** P3D.4: atomicity, publication visibility, content and media integrity. */
final class CmsTourIntegrityRegressionTest extends TourQaTestCase
{
    public function test_invalid_publication_rolls_back_new_tour_tags_links_and_audit(): void
    {
        $category = $this->category('not-yet-public');
        $destination = $this->destination('public-location', 'published');
        $payload = $this->payload('unready-publication');
        $payload['status'] = 'published';
        $payload['category_ids'] = [$category->id];
        $payload['destination_ids'] = [$destination->id];
        $payload['tag_names'] = ['Should Roll Back'];
        $this->actingAs($this->staff('administrator'))
            ->postJson('/admin/internal/tours', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('category_ids');
        $this->assertDatabaseMissing('travel_tours', ['slug' => 'unready-publication']);
        $this->assertDatabaseCount('travel_tour_tags', 0);
        $this->assertDatabaseCount('travel_tour_tag', 0);
        $this->assertDatabaseCount('cms_audit_events', 0);
    }

    public function test_invalid_tags_do_not_partially_create_a_tour_or_shared_tags(): void
    {
        $payload = $this->payload('invalid-tag-rollback');
        $payload['tag_names'] = ['Harmless', '<script>alert(1)</script>'];
        $this->actingAs($this->staff('content_editor'))
            ->postJson('/admin/internal/tours', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('tag_names');
        $this->assertDatabaseCount('travel_tours', 0);
        $this->assertDatabaseCount('travel_tour_tags', 0);
    }

    public function test_repeated_identical_tag_update_is_idempotent_and_not_reaudited(): void
    {
        $tour = $this->tour('idempotent-tags');
        $this->actingAs($this->staff('content_editor'));
        $this->putJson('/admin/internal/tours/'.$tour->id, [
            'tag_names' => ['Family', 'Adventure'],
        ])->assertOk()->assertJsonCount(2, 'data.tag_names');
        $auditCount = DB::table('cms_audit_events')->where('action', 'tour.tags.changed')->count();
        self::assertSame(1, $auditCount);
        $this->putJson('/admin/internal/tours/'.$tour->id, [
            'tag_names' => ['Family', 'Adventure'],
        ])->assertOk();
        self::assertSame($auditCount, DB::table('cms_audit_events')->where('action', 'tour.tags.changed')->count());
        self::assertSame(2, TourTag::query()->count());
    }

    public function test_slug_cannot_change_or_be_reused_after_soft_deletion(): void
    {
        $tour = $this->tour('permanent-link');
        $this->actingAs($this->staff('content_editor'));
        $this->putJson('/admin/internal/tours/'.$tour->id, ['slug' => 'changed-link'])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->deleteJson('/admin/internal/tours/'.$tour->id)->assertNoContent();
        $this->postJson('/admin/internal/tours', $this->payload('permanent-link'))
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        self::assertSame(1, Tour::withTrashed()->where('slug', 'permanent-link')->count());
    }

    public function test_withdrawing_published_taxonomy_removes_tour_from_catalog_without_mutating_tour(): void
    {
        $category = $this->category('forest', 'published');
        $destination = $this->destination('sreemangal', 'published');
        $tour = $this->tour('forest-walk', [
            'status' => 'published', 'published_at' => now(), 'is_featured' => true,
        ]);
        $tour->categories()->attach($category->id, ['sort_order' => 0]);
        $tour->destinations()->attach($destination->id, ['sort_order' => 0]);
        self::assertCount(1, app(PublishedTourCatalog::class)->tours('forest', 'sreemangal', 10, true));
        $destination->status = \App\Domain\Cms\Enums\PublicationStatus::Draft;
        $destination->save();
        self::assertSame([], app(PublishedTourCatalog::class)->tours());
        self::assertSame('published', $tour->refresh()->status->value);
    }

    public function test_tag_limit_rejects_over_twenty_without_partial_tour_creation(): void
    {
        $payload = $this->payload('too-many-tags');
        $payload['tag_names'] = array_map(static fn (int $n): string => 'Tag '.$n, range(1, 21));
        $this->actingAs($this->staff('content_editor'))
            ->postJson('/admin/internal/tours', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('tag_names');
        $this->assertDatabaseCount('travel_tours', 0);
        $this->assertDatabaseCount('travel_tour_tags', 0);
    }

    public function test_description_referenced_image_cannot_be_deleted_until_editor_unlinks_it(): void
    {
        if (! function_exists('imagecreatefromstring')) {
            self::markTestSkipped('GD extension required for description/media consistency test.');
        }
        Storage::fake('public');
        $tour = $this->tour('referenced-photo');
        $this->actingAs($this->staff('content_editor'));
        $uploaded = $this->post('/admin/internal/tours/'.$tour->id.'/media', [
            'image' => UploadedFile::fake()->image('embedded.jpg', 180, 180),
        ], ['Accept' => 'application/json'])->assertCreated();
        $imageId = (int) $uploaded->json('data.id');
        $url = (string) $uploaded->json('data.url');
        $path = (string) $uploaded->json('data.path');
        $this->putJson('/admin/internal/tours/'.$tour->id, [
            'description' => '<p>Our journey</p><img src="'.$url.'" alt="Journey">',
        ])->assertOk();
        $this->deleteJson('/admin/internal/tours/'.$tour->id.'/media/'.$imageId)
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        Storage::disk('public')->assertExists($path);
        $this->putJson('/admin/internal/tours/'.$tour->id, [
            'description' => '<p>Updated journey without image</p>',
        ])->assertOk();
        $this->deleteJson('/admin/internal/tours/'.$tour->id.'/media/'.$imageId)->assertNoContent();
        Storage::disk('public')->assertMissing($path);
    }

    public function test_audit_payload_does_not_contain_raw_tour_description(): void
    {
        $tour = $this->tour('audit-privacy');
        $secret = 'DescriptionOnlyForEditorialReview159753';
        $this->actingAs($this->staff('content_editor'))
            ->putJson('/admin/internal/tours/'.$tour->id, [
                'description' => '<p>'.$secret.'</p>',
            ])->assertOk();
        $rows = DB::table('cms_audit_events')->get();
        self::assertNotEmpty($rows);
        foreach ($rows as $event) {
            self::assertStringNotContainsString($secret, (string) json_encode($event));
        }
    }
}
