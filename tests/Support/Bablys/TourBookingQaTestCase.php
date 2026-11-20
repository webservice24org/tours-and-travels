<?php

declare(strict_types=1);

namespace Tests\Support\Bablys;

use App\Models\Travel\Tour;
use App\Models\Travel\TourDeparture;
use App\Models\Travel\TourPriceVariant;

/** Fixture for P3E.5A regression tests; relies on completed P3D.4 shared test case. */
abstract class TourBookingQaTestCase extends TourQaTestCase
{
    /** @return array{0:Tour,1:TourDeparture} */
    protected function readyTour(string $slug = 'booking-ready'): array
    {
        $tour = $this->tour($slug, [
            'status' => 'published',
            'published_at' => now('UTC')->subDay(),
            'duration_days' => 3,
            'duration_nights' => 2,
        ]);
        $tour->categories()->attach($this->category($slug.'-category', 'published')->getKey());
        $tour->destinations()->attach($this->destination($slug.'-destination', 'published')->getKey());
        $this->addPersonPrice($tour);

        $departure = TourDeparture::query()->create([
            'tour_id' => (int) $tour->getKey(),
            'code' => 'summer-a',
            'name' => 'Summer departure',
            'timezone_name' => 'Asia/Dhaka',
            'starts_at' => now('UTC')->addMonths(2)->format('Y-m-d H:i:s'),
            'ends_at' => now('UTC')->addMonths(2)->addDays(3)->format('Y-m-d H:i:s'),
            'booking_closes_at' => now('UTC')->addMonth()->format('Y-m-d H:i:s'),
            'capacity_total' => 10,
            'status' => 'open',
        ]);
        return [$tour, $departure];
    }

    protected function addPersonPrice(Tour $tour): TourPriceVariant
    {
        $variant = TourPriceVariant::query()->create([
            'tour_id' => (int) $tour->getKey(),
            'code' => 'standard-bdt',
            'name' => 'Standard BDT',
            'currency' => 'BDT',
            'pricing_model' => 'per_person',
            'min_travelers' => 1,
            'max_travelers' => 10,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $variant->rates()->create([
            'traveler_type' => 'adult',
            'regular_minor' => 1500000,
            'sale_minor' => null,
            'sale_starts_at' => null,
            'sale_ends_at' => null,
        ]);
        $variant->rates()->create([
            'traveler_type' => 'child',
            'regular_minor' => 800000,
            'sale_minor' => null,
            'sale_starts_at' => null,
            'sale_ends_at' => null,
        ]);
        return $variant;
    }
}
