<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingLifecycleManager;
use App\Models\Travel\TourBooking;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Bablys\TourBookingQaTestCase;

final class TourBookingSchemaTest extends TourBookingQaTestCase
{
    public function test_table_has_booking_history_and_money_snapshot_columns(): void
    {
        self::assertTrue(Schema::hasTable('travel_tour_bookings'));
        foreach ([
            'tour_id', 'departure_id', 'seat_allocation_id', 'reference_key', 'status',
            'payment_state', 'currency', 'fraction_digits', 'regular_total_minor',
            'discount_minor', 'total_minor', 'price_lines_snapshot', 'created_by',
            'cancelled_by', 'quoted_at_utc', 'departure_starts_at_utc',
        ] as $column) {
            self::assertTrue(Schema::hasColumn('travel_tour_bookings', $column), $column);
        }
    }

    public function test_booking_model_relationships_resolve_to_saved_tour_and_allocation(): void
    {
        [$tour, $departure] = $this->readyTour('booking-schema');
        $booking = app(TourBookingLifecycleManager::class)->createInternal(
            $this->staff('administrator'), $tour, $departure, 'BOOK-schema-0001', 'standard-bdt', 1
        );
        $found = TourBooking::query()->with('tour', 'departure', 'allocation')->firstOrFail();
        self::assertSame($tour->getKey(), $found->tour?->getKey());
        self::assertSame($departure->getKey(), $found->departure?->getKey());
        self::assertSame($booking->seat_allocation_id, $found->allocation?->getKey());
    }

    public function test_booking_price_lines_snapshot_cast_is_array(): void
    {
        [$tour, $departure] = $this->readyTour('booking-array');
        $booking = app(TourBookingLifecycleManager::class)->createInternal(
            $this->staff('administrator'), $tour, $departure, 'BOOK-json-000001', 'standard-bdt', 1
        );
        self::assertIsArray($booking->fresh()->price_lines_snapshot);
        self::assertSame('adult', $booking->fresh()->price_lines_snapshot[0]['traveler_type']);
    }
}
