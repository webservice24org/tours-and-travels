<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Travel\Tour;
use App\Models\Travel\TourItineraryDay;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TourItinerarySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_itinerary_table_and_expected_columns_exist(): void
    {
        self::assertTrue(Schema::hasTable('travel_tour_itinerary_days'));
        foreach (['tour_id', 'day_number', 'title', 'description', 'location_label', 'overnight_location', 'meals', 'highlights'] as $column) {
            self::assertTrue(Schema::hasColumn('travel_tour_itinerary_days', $column));
        }
    }

    public function test_two_tours_can_have_day_one_but_one_tour_cannot_duplicate_day_one(): void
    {
        $first = $this->tour('first');
        $second = $this->tour('second');
        $this->day($first, 1);
        $this->day($second, 1);
        $this->expectException(QueryException::class);
        $this->day($first, 1);
    }

    public function test_force_deleting_parent_tour_cascades_its_days_only(): void
    {
        $first = $this->tour('first');
        $second = $this->tour('second');
        $this->day($first, 1);
        $this->day($second, 1);
        $first->forceDelete();
        $this->assertDatabaseMissing('travel_tour_itinerary_days', ['tour_id' => $first->id]);
        $this->assertDatabaseCount('travel_tour_itinerary_days', 1);
    }

    public function test_soft_deleting_tour_preserves_days_for_recovery(): void
    {
        $tour = $this->tour('recoverable');
        $this->day($tour, 1);
        $tour->delete();
        $this->assertDatabaseHas('travel_tour_itinerary_days', ['tour_id' => $tour->id]);
    }

    private function tour(string $slug): Tour
    {
        return Tour::query()->create(['title' => 'Tour '.$slug, 'slug' => $slug]);
    }

    private function day(Tour $tour, int $number): TourItineraryDay
    {
        return TourItineraryDay::query()->create([
            'tour_id' => $tour->id, 'day_number' => $number,
            'title' => 'Day '.$number, 'meals' => [], 'highlights' => [],
        ]);
    }
}
