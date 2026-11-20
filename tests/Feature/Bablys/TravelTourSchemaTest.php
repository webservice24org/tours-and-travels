<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Models\Travel\Destination;
use App\Models\Travel\Tour;
use App\Models\Travel\TourCategory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TravelTourSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_tour_and_two_relation_tables_exist(): void
    {
        self::assertTrue(Schema::hasTable('travel_tours'));
        self::assertTrue(Schema::hasTable('travel_tour_category'));
        self::assertTrue(Schema::hasTable('travel_tour_destination'));
        foreach (['slug', 'status', 'duration_days', 'published_at', 'scheduled_for', 'deleted_at'] as $column) {
            self::assertTrue(Schema::hasColumn('travel_tours', $column));
        }
    }

    public function test_tour_defaults_to_draft_and_never_has_an_inferred_price(): void
    {
        $tour = $this->tour();
        self::assertSame(PublicationStatus::Draft, $tour->fresh()?->status);
        self::assertSame(1, $tour->fresh()?->duration_days);
        self::assertFalse($tour->fresh()?->is_featured);
        self::assertFalse(Schema::hasColumn('travel_tours', 'price'));
        self::assertFalse(Schema::hasColumn('travel_tours', 'available_seats'));
    }

    public function test_slug_remains_unique_after_soft_deletion(): void
    {
        $this->tour(['slug' => 'permanent-slug'])->delete();
        $this->expectException(QueryException::class);
        $this->tour(['slug' => 'permanent-slug']);
    }

    public function test_pivots_prevent_duplicate_assignments(): void
    {
        $tour = $this->tour();
        $category = $this->category();
        $tour->categories()->attach($category->id);
        $this->expectException(QueryException::class);
        $tour->categories()->attach($category->id);
    }

    public function test_category_and_destination_relationships_are_many_to_many(): void
    {
        $tour = $this->tour();
        $first = $this->category(['slug' => 'first', 'name' => 'First']);
        $second = $this->category(['slug' => 'second', 'name' => 'Second']);
        $destination = $this->destination();
        $tour->categories()->attach($second->id, ['sort_order' => 8]);
        $tour->categories()->attach($first->id, ['sort_order' => 1]);
        $tour->destinations()->attach($destination->id, ['sort_order' => 0]);
        self::assertSame(['first', 'second'], $tour->categories->pluck('slug')->all());
        self::assertSame([$destination->slug], $tour->destinations->pluck('slug')->all());
        self::assertSame(8, (int) $tour->categories->last()?->pivot?->sort_order);
    }

    public function test_soft_deleted_tour_is_hidden_but_preserved(): void
    {
        $tour = $this->tour();
        $tour->delete();
        self::assertSame(0, Tour::query()->count());
        self::assertSame(1, Tour::withTrashed()->count());
    }

    public function test_hard_deleting_tour_cascades_assignment_records(): void
    {
        $tour = $this->tour();
        $category = $this->category();
        $destination = $this->destination();
        $tour->categories()->attach($category->id);
        $tour->destinations()->attach($destination->id);
        $tour->forceDelete();
        self::assertDatabaseMissing('travel_tour_category', ['tour_id' => $tour->id]);
        self::assertDatabaseMissing('travel_tour_destination', ['tour_id' => $tour->id]);
        self::assertDatabaseHas('travel_tour_categories', ['id' => $category->id]);
        self::assertDatabaseHas('travel_destinations', ['id' => $destination->id]);
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
