<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Travel\Tour;
use App\Models\Travel\TourPriceRate;
use App\Models\Travel\TourPriceVariant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TourPricingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_variant_and_rate_tables_have_integer_money_columns(): void
    {
        self::assertTrue(Schema::hasTable('travel_tour_price_variants'));
        self::assertTrue(Schema::hasTable('travel_tour_price_rates'));
        foreach (['tour_id','code','currency','pricing_model','min_travelers','max_travelers','sort_order','is_active'] as $col) {
            self::assertTrue(Schema::hasColumn('travel_tour_price_variants', $col));
        }
        foreach (['variant_id','traveler_type','regular_minor','sale_minor','sale_starts_at','sale_ends_at'] as $col) {
            self::assertTrue(Schema::hasColumn('travel_tour_price_rates', $col));
        }
    }

    public function test_variant_code_is_unique_within_tour_but_reusable_across_tours(): void
    {
        $first = $this->tour('first');
        $second = $this->tour('second');
        $this->variant($first, 'standard');
        $this->variant($second, 'standard');
        self::assertSame(2, TourPriceVariant::query()->count());
        $this->expectException(QueryException::class);
        $this->variant($first, 'standard');
    }

    public function test_rate_type_is_unique_per_variant(): void
    {
        $variant = $this->variant($this->tour('unique'), 'standard');
        $this->rate($variant, 'adult');
        $this->expectException(QueryException::class);
        $this->rate($variant, 'adult');
    }

    public function test_soft_delete_keeps_pricing_but_force_delete_cascades(): void
    {
        $tour = $this->tour('recover');
        $variant = $this->variant($tour, 'standard');
        $this->rate($variant, 'adult');
        $tour->delete();
        $this->assertDatabaseHas('travel_tour_price_variants', ['id' => $variant->id]);
        $tour->forceDelete();
        $this->assertDatabaseMissing('travel_tour_price_variants', ['id' => $variant->id]);
        $this->assertDatabaseCount('travel_tour_price_rates', 0);
    }

    private function tour(string $slug): Tour
    {
        return Tour::query()->create(['title' => 'Tour '.$slug, 'slug' => $slug]);
    }

    private function variant(Tour $tour, string $code): TourPriceVariant
    {
        return TourPriceVariant::query()->create([
            'tour_id' => $tour->id, 'code' => $code, 'name' => 'Standard',
            'currency' => 'BDT', 'pricing_model' => 'per_person',
            'min_travelers' => 1, 'is_active' => true, 'sort_order' => 0,
        ]);
    }

    private function rate(TourPriceVariant $variant, string $type): TourPriceRate
    {
        return TourPriceRate::query()->create([
            'variant_id' => $variant->id, 'traveler_type' => $type, 'regular_minor' => 5000,
        ]);
    }
}
