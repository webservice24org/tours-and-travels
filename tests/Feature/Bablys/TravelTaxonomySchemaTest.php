<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Models\Travel\Destination;
use App\Models\Travel\TourCategory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TravelTaxonomySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_tables_and_columns_exist(): void
    {
        self::assertTrue(Schema::hasTable('travel_tour_categories'));
        self::assertTrue(Schema::hasTable('travel_destinations'));
        self::assertTrue(Schema::hasColumn('travel_tour_categories', 'parent_id'));
        self::assertTrue(Schema::hasColumn('travel_tour_categories', 'deleted_at'));
        self::assertTrue(Schema::hasColumn('travel_destinations', 'country_code'));
        self::assertTrue(Schema::hasColumn('travel_destinations', 'latitude'));
    }

    public function test_taxonomy_records_default_to_draft(): void
    {
        $category = TourCategory::query()->create(['name' => 'Adventure', 'slug' => 'adventure']);
        $destination = Destination::query()->create([
            'name' => 'Coxs Bazar', 'slug' => 'coxs-bazar', 'country_code' => 'BD',
        ]);

        self::assertSame(PublicationStatus::Draft, $category->fresh()?->status);
        self::assertSame(PublicationStatus::Draft, $destination->fresh()?->status);
    }

    public function test_parent_child_category_relationships_work(): void
    {
        $parent = TourCategory::query()->create(['name' => 'Bangladesh', 'slug' => 'bangladesh']);
        $child = TourCategory::query()->create([
            'parent_id' => $parent->id, 'name' => 'Beach', 'slug' => 'beach',
        ]);

        self::assertSame($parent->id, $child->parent?->id);
        self::assertSame($child->id, $parent->children()->first()?->id);
    }

    public function test_category_slug_has_database_uniqueness_constraint(): void
    {
        TourCategory::query()->create(['name' => 'First', 'slug' => 'unique-category']);
        $this->expectException(QueryException::class);
        TourCategory::query()->create(['name' => 'Second', 'slug' => 'unique-category']);
    }

    public function test_destination_slug_has_database_uniqueness_constraint(): void
    {
        Destination::query()->create(['name' => 'First', 'slug' => 'unique-place', 'country_code' => 'BD']);
        $this->expectException(QueryException::class);
        Destination::query()->create(['name' => 'Second', 'slug' => 'unique-place', 'country_code' => 'TH']);
    }

    public function test_soft_deletion_hides_rows_and_preserves_slug_history(): void
    {
        $category = TourCategory::query()->create(['name' => 'Historical', 'slug' => 'historical']);
        $destination = Destination::query()->create([
            'name' => 'Historical Place', 'slug' => 'historical-place', 'country_code' => 'BD',
        ]);
        $category->delete();
        $destination->delete();

        self::assertSame(0, TourCategory::query()->count());
        self::assertSame(0, Destination::query()->count());
        self::assertSame(1, TourCategory::withTrashed()->count());
        self::assertSame(1, Destination::withTrashed()->count());
    }

    public function test_destination_coordinates_are_decimal_and_nullable(): void
    {
        $destination = Destination::query()->create([
            'name' => 'Sylhet', 'slug' => 'sylhet', 'country_code' => 'BD',
            'latitude' => '24.8949000', 'longitude' => '91.8687000',
        ]);

        self::assertSame('24.8949000', $destination->fresh()?->latitude);
        self::assertSame('91.8687000', $destination->fresh()?->longitude);
    }
}
