<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Domain\Travel\Tours\PublishedTourCatalog;
use App\Models\Travel\Destination;
use App\Models\Travel\Tour;
use App\Models\Travel\TourCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TravelTourPublishedCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_tour_with_active_taxonomy_is_visible(): void
    {
        $this->publishableTour(['title' => 'Coastal Journey', 'slug' => 'coastal-journey']);
        $rows = app(PublishedTourCatalog::class)->tours();
        self::assertSame(['coastal-journey'], array_column($rows, 'slug'));
        self::assertSame(1, $rows[0]['duration_days']);
    }

    public function test_draft_scheduled_and_future_publications_never_appear(): void
    {
        $this->publishableTour(['slug' => 'visible']);
        $this->publishableTour(['slug' => 'draft', 'status' => PublicationStatus::Draft]);
        $this->publishableTour([
            'slug' => 'scheduled', 'status' => PublicationStatus::Scheduled,
            'scheduled_for' => now()->addDays(2),
        ]);
        $this->publishableTour(['slug' => 'future', 'published_at' => now()->addDays(2)]);
        self::assertSame(['visible'], array_column(app(PublishedTourCatalog::class)->tours(), 'slug'));
    }

    public function test_unpublished_or_deleted_taxonomy_hides_linked_tours(): void
    {
        [$tour, $category, $destination] = $this->publishableTour(['slug' => 'hidden']);
        $category->update(['status' => PublicationStatus::Archived]);
        self::assertSame([], app(PublishedTourCatalog::class)->tours());
        $category->update(['status' => PublicationStatus::Published]);
        $destination->delete();
        self::assertSame([], app(PublishedTourCatalog::class)->tours());
        self::assertSame('hidden', $tour->slug);
    }

    public function test_filters_only_match_published_associations(): void
    {
        [$tour, $category, $destination] = $this->publishableTour(['slug' => 'alpha']);
        [$otherTour] = $this->publishableTour(['slug' => 'beta']);
        $otherCategory = TourCategory::query()->create([
            'name' => 'International', 'slug' => 'published-international',
            'status' => PublicationStatus::Published,
        ]);
        $otherDestination = Destination::query()->create([
            'name' => 'Phuket', 'slug' => 'published-phuket', 'country_code' => 'TH',
            'status' => PublicationStatus::Published,
        ]);
        $otherTour->categories()->sync([$otherCategory->id]);
        $otherTour->destinations()->sync([$otherDestination->id]);
        $catalog = app(PublishedTourCatalog::class);
        self::assertSame(['alpha', 'beta'], array_column($catalog->tours(), 'slug'));
        self::assertSame(['alpha'], array_column($catalog->tours($category->slug, $destination->slug), 'slug'));
        self::assertSame(['beta'], array_column($catalog->tours($otherCategory->slug, $otherDestination->slug), 'slug'));
        self::assertSame([], $catalog->tours('no-such-category'));
        self::assertSame([], $catalog->tours(null, 'no-such-destination'));
        self::assertSame('alpha', $tour->slug);
    }

    public function test_featured_filter_limit_and_sort_are_deterministic(): void
    {
        $this->publishableTour(['slug' => 'later', 'sort_order' => 10, 'is_featured' => true]);
        $this->publishableTour(['slug' => 'first', 'sort_order' => 1, 'is_featured' => true]);
        $this->publishableTour(['slug' => 'not-featured', 'sort_order' => 0, 'is_featured' => false]);
        $catalog = app(PublishedTourCatalog::class);
        self::assertSame(['first'], array_column($catalog->tours(limit: 0, featuredOnly: true), 'slug'));
        self::assertSame(['not-featured', 'first', 'later'], array_column($catalog->tours(), 'slug'));
    }

    public function test_deleted_tour_and_price_inventory_fields_are_not_exposed(): void
    {
        [$tour] = $this->publishableTour(['slug' => 'removed']);
        $tour->delete();
        self::assertSame([], app(PublishedTourCatalog::class)->tours());
        $this->publishableTour(['slug' => 'visible']);
        $data = app(PublishedTourCatalog::class)->tours()[0];
        self::assertArrayNotHasKey('price', $data);
        self::assertArrayNotHasKey('availability', $data);
        self::assertArrayNotHasKey('booking_url', $data);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array{Tour, TourCategory, Destination}
     */
    private function publishableTour(array $attributes = []): array
    {
        $tour = Tour::query()->create(array_merge([
            'title' => 'Sample Tour',
            'slug' => 'tour-'.(Tour::withTrashed()->count() + 1),
            'status' => PublicationStatus::Published,
            'published_at' => now()->subDay(),
        ], $attributes));

        // The same published taxonomy may be used by multiple tour records.
        $category = TourCategory::query()->firstOrCreate(
            ['slug' => 'published-domestic'],
            ['name' => 'Domestic', 'status' => PublicationStatus::Published]
        );
        $destination = Destination::query()->firstOrCreate(
            ['slug' => 'published-dhaka'],
            ['name' => 'Dhaka', 'country_code' => 'BD', 'status' => PublicationStatus::Published]
        );
        $tour->categories()->attach($category->id);
        $tour->destinations()->attach($destination->id);

        return [$tour, $category, $destination];
    }
}
