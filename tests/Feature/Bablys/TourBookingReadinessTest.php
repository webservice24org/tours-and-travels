<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingReadiness;
use App\Models\Travel\TourPriceRate;
use App\Models\Travel\TourPriceVariant;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bablys\TourBookingQaTestCase;

final class TourBookingReadinessTest extends TourBookingQaTestCase
{
    public function test_published_tour_with_open_departure_and_price_is_internally_ready(): void
    {
        [$tour, $departure] = $this->readyTour('ready-inspection');
        $info = app(TourBookingReadiness::class)->inspect(
            $tour, $departure, 'standard-bdt', 2, 1, now('UTC')
        );
        self::assertTrue($info['ready_for_internal_allocation']);
        self::assertFalse($info['public_bookable']);
        self::assertSame([], $info['reasons']);
        self::assertSame(10, $info['remaining_seats']);
        self::assertSame('BDT', $info['quote']['currency']);
        self::assertSame(3800000, $info['quote']['total_minor']);
    }

    public function test_draft_and_scheduled_tours_are_not_ready(): void
    {
        [$tour, $departure] = $this->readyTour('not-live');
        $tour->status = 'draft';
        $tour->save();
        $first = $this->check($tour, $departure);
        self::assertContains('tour_not_published', $first['reasons']);
        $tour->status = 'scheduled';
        $tour->save();
        self::assertContains('tour_not_published', $this->check($tour, $departure)['reasons']);
    }

    public function test_future_publish_date_blocks_ready_tour(): void
    {
        [$tour, $departure] = $this->readyTour('future-publication');
        $tour->published_at = now('UTC')->addDay();
        $tour->save();
        self::assertContains('tour_not_published', $this->check($tour, $departure)['reasons']);
    }

    public function test_unpublished_taxonomy_blocks_ready_tour(): void
    {
        [$tour, $departure] = $this->readyTour('taxonomy-ineligible');
        $tour->categories()->firstOrFail()->update(['status' => 'draft']);
        self::assertContains('no_published_category', $this->check($tour, $departure)['reasons']);
        $tour->categories()->firstOrFail()->update(['status' => 'published']);
        $tour->destinations()->firstOrFail()->update(['status' => 'archived']);
        self::assertContains('no_published_destination', $this->check($tour, $departure)['reasons']);
    }

    public function test_mismatched_departure_and_tour_are_not_ready(): void
    {
        [$tour] = $this->readyTour('mismatch-one');
        [, $foreignDeparture] = $this->readyTour('mismatch-two');
        self::assertContains('departure_tour_mismatch', $this->check($tour, $foreignDeparture)['reasons']);
    }

    public function test_draft_closed_cancelled_and_departed_departures_not_ready(): void
    {
        [$tour, $departure] = $this->readyTour('closed-departures');
        foreach (['draft', 'closed', 'cancelled'] as $status) {
            $departure->status = $status;
            $departure->save();
            self::assertContains('departure_not_open', $this->check($tour, $departure)['reasons']);
        }
        $departure->status = 'open';
        $departure->starts_at = now('UTC')->subMinute()->format('Y-m-d H:i:s');
        $departure->save();
        self::assertContains('departure_started', $this->check($tour, $departure)['reasons']);
    }

    public function test_booking_cutoff_closes_readiness(): void
    {
        [$tour, $departure] = $this->readyTour('booking-cutoff');
        $departure->booking_closes_at = now('UTC')->subMinute()->format('Y-m-d H:i:s');
        $departure->save();
        self::assertContains('booking_cutoff_passed', $this->check($tour, $departure)['reasons']);
    }

    public function test_remaining_seats_cannot_be_exceeded(): void
    {
        [$tour, $departure] = $this->readyTour('capacity-ready');
        DB::table('travel_tour_departures')->where('id', $departure->id)->update(['seats_committed' => 9]);
        $info = app(TourBookingReadiness::class)->inspect(
            $tour, $departure->fresh(), 'standard-bdt', 2, 0, now('UTC')
        );
        self::assertSame(1, $info['remaining_seats']);
        self::assertContains('insufficient_capacity', $info['reasons']);
    }

    public function test_missing_or_inactive_price_option_blocks_readiness(): void
    {
        [$tour, $departure] = $this->readyTour('price-inactive');
        self::assertContains('pricing_option_missing', app(TourBookingReadiness::class)
            ->inspect($tour, $departure, 'wrong-code', 1, 0, now('UTC'))['reasons']);
        TourPriceVariant::query()->where('tour_id', $tour->id)->update(['is_active' => false]);
        self::assertContains('pricing_not_eligible', $this->check($tour, $departure)['reasons']);
    }

    public function test_no_child_rate_prevents_child_booking(): void
    {
        [$tour, $departure] = $this->readyTour('child-rate-missing');
        TourPriceRate::query()->where('traveler_type', 'child')->delete();
        $info = app(TourBookingReadiness::class)->inspect(
            $tour, $departure, 'standard-bdt', 1, 1, now('UTC')
        );
        self::assertContains('pricing_not_eligible', $info['reasons']);
    }

    public function test_incorrect_party_sizes_are_not_ready(): void
    {
        [$tour, $departure] = $this->readyTour('invalid-party');
        $info = app(TourBookingReadiness::class)->inspect(
            $tour, $departure, 'standard-bdt', 0, -1, now('UTC')
        );
        self::assertContains('invalid_party_size', $info['reasons']);
    }

    public function test_readiness_inspection_is_read_only(): void
    {
        [$tour, $departure] = $this->readyTour('read-only-check');
        $before = DB::table('cms_audit_events')->count();
        $this->check($tour, $departure);
        self::assertDatabaseCount('travel_tour_seat_allocations', 0);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        self::assertSame($before, DB::table('cms_audit_events')->count());
    }

    /** @return array<string,mixed> */
    private function check(\App\Models\Travel\Tour $tour, \App\Models\Travel\TourDeparture $departure): array
    {
        return app(TourBookingReadiness::class)->inspect(
            $tour, $departure, 'standard-bdt', 1, 0, now('UTC')
        );
    }
}
