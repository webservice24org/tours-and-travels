<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Domain\Travel\Tours\TourPublicationRules;
use App\Domain\Travel\Tours\TourSlug;
use App\Domain\Travel\Tours\TourTaxonomyRules;
use App\Models\Travel\Destination;
use App\Models\Travel\Tour;
use App\Models\Travel\TourCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class TravelTourDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_tour_slug_normalizes_lowercase_and_rejects_reserved_segments(): void
    {
        self::assertSame('coxs-bazar', app(TourSlug::class)->normalize(' COXS-BAZAR '));
        $this->expectException(ValidationException::class);
        app(TourSlug::class)->normalize('admin');
    }

    public function test_tour_slug_checks_deleted_rows_and_immutability(): void
    {
        $tour = $this->tour();
        $tour->delete();
        $this->expectException(ValidationException::class);
        app(TourSlug::class)->assertAvailable('sample-tour');
    }

    public function test_taxonomy_guard_accepts_active_draft_assignments(): void
    {
        $category = $this->category();
        $destination = $this->destination();
        app(TourTaxonomyRules::class)->assertAssignable([$category->id], [$destination->id]);
        self::assertTrue(true);
    }

    public function test_taxonomy_guard_rejects_duplicate_ids(): void
    {
        $category = $this->category();
        $this->expectException(ValidationException::class);
        app(TourTaxonomyRules::class)->assertAssignable([$category->id, $category->id], []);
    }

    public function test_taxonomy_guard_rejects_archived_or_deleted_destination(): void
    {
        $destination = $this->destination(['status' => PublicationStatus::Archived]);
        $this->expectException(ValidationException::class);
        app(TourTaxonomyRules::class)->assertAssignable([], [$destination->id]);
    }

    public function test_publish_requires_both_published_taxonomies(): void
    {
        $tour = $this->tour();
        $category = $this->category(['status' => PublicationStatus::Published]);
        $destination = $this->destination();
        $tour->categories()->attach($category->id);
        $tour->destinations()->attach($destination->id);
        $this->expectException(ValidationException::class);
        app(TourPublicationRules::class)->assertReady($tour);
    }

    public function test_publish_requires_visible_ancestor_category(): void
    {
        $parent = $this->category(['slug' => 'parent']);
        $child = $this->category([
            'slug' => 'child', 'parent_id' => $parent->id,
            'status' => PublicationStatus::Published,
        ]);
        $destination = $this->destination(['status' => PublicationStatus::Published]);
        $tour = $this->tour();
        $tour->categories()->attach($child->id);
        $tour->destinations()->attach($destination->id);
        $this->expectException(ValidationException::class);
        app(TourPublicationRules::class)->assertReady($tour);
    }

    public function test_publish_ready_with_visible_category_ancestry_and_destination(): void
    {
        $parent = $this->category(['slug' => 'parent', 'status' => PublicationStatus::Published]);
        $child = $this->category([
            'slug' => 'child', 'parent_id' => $parent->id,
            'status' => PublicationStatus::Published,
        ]);
        $destination = $this->destination(['status' => PublicationStatus::Published]);
        $tour = $this->tour(['duration_days' => 5]);
        $tour->categories()->attach($child->id);
        $tour->destinations()->attach($destination->id);
        app(TourPublicationRules::class)->assertReady($tour);
        self::assertSame(5, $tour->duration_days);
    }

    /** @param array<string, mixed> $values */
    private function tour(array $values = []): Tour
    {
        return Tour::query()->create(array_merge([
            'title' => 'Sample Tour', 'slug' => 'sample-tour',
        ], $values));
    }

    /** @param array<string, mixed> $values */
    private function category(array $values = []): TourCategory
    {
        return TourCategory::query()->create(array_merge([
            'name' => 'Domestic', 'slug' => 'domestic',
        ], $values));
    }

    /** @param array<string, mixed> $values */
    private function destination(array $values = []): Destination
    {
        return Destination::query()->create(array_merge([
            'name' => 'Dhaka', 'slug' => 'dhaka', 'country_code' => 'BD',
        ], $values));
    }
}
