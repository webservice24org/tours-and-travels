<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Tours\PublishedTourDetail;
use App\Models\Travel\Tour;
use App\Models\Travel\TourDeparture;
use App\Models\Travel\TourItineraryDay;
use Tests\Support\Bablys\TourBookingQaTestCase;

/** P3F.3B.2: itinerary is public plain text; departure data is preview-only. */
final class PublicTourItineraryDepartureTest extends TourBookingQaTestCase
{
    /** @param array<string, mixed> $overrides */
    private function day(Tour $tour, int $number, array $overrides = []): TourItineraryDay
    {
        return TourItineraryDay::query()->create(array_merge([
            'tour_id' => (int) $tour->getKey(),
            'day_number' => $number,
            'title' => 'Day '.$number.' adventure',
            'location_label' => 'Sylhet',
            'description' => 'Explore the green hills.',
            'overnight_location' => 'Sylhet hotel',
            'meals' => ['Breakfast', 'Dinner'],
            'highlights' => ['Tea garden', 'River cruise'],
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function departure(Tour $tour, string $code, array $overrides = []): TourDeparture
    {
        return TourDeparture::query()->create(array_merge([
            'tour_id' => (int) $tour->getKey(),
            'code' => $code,
            'name' => 'Public date '.$code,
            'timezone_name' => 'Asia/Dhaka',
            'starts_at' => now('UTC')->addDays(60)->format('Y-m-d H:i:s'),
            'ends_at' => now('UTC')->addDays(63)->format('Y-m-d H:i:s'),
            'booking_closes_at' => now('UTC')->addDays(30)->format('Y-m-d H:i:s'),
            'capacity_total' => 3,
            'status' => 'open',
            'meeting_point' => 'CONFIDENTIAL-HOTEL',
            'internal_notes' => 'CONFIDENTIAL-INTERNAL-OPERATIONS',
        ], $overrides));
    }

    public function test_published_tour_shows_day_by_day_itinerary_in_day_order(): void
    {
        [$tour] = $this->readyTour('p3f3b2-days');
        $this->day($tour, 2, ['title' => 'River cruise']);
        $this->day($tour, 1, ['title' => 'Welcome to Sylhet']);

        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->component('public/tours/show')
            ->has('tour.itinerary', 2)
            ->where('tour.itinerary.0.day_number', 1)
            ->where('tour.itinerary.0.title', 'Welcome to Sylhet')
            ->where('tour.itinerary.0.meals.0', 'Breakfast')
            ->where('tour.itinerary.0.highlights.0', 'Tea garden')
            ->where('tour.itinerary.0.overnight', 'Sylhet hotel')
            ->where('tour.itinerary.1.day_number', 2)
            ->where('tour.itinerary.1.title', 'River cruise'));
    }

    public function test_itinerary_plain_text_never_exposes_html_markup(): void
    {
        [$tour] = $this->readyTour('p3f3b2-plain');
        $this->day($tour, 1, [
            'title' => '<b>Day one</b>',
            'description' => 'Safe <img src=x onerror=alert(1)> walk',
            'location_label' => '<i>Sylhet</i>',
            'meals' => ['<script>bad</script>Lunch', 'Dinner'],
            'highlights' => ['<b>Tea</b> garden'],
        ]);
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.itinerary.0.title', 'Day one')
            ->where('tour.itinerary.0.description', 'Safe  walk')
            ->where('tour.itinerary.0.location', 'Sylhet')
            ->where('tour.itinerary.0.meals.1', 'Dinner')
            ->where('tour.itinerary.0.highlights.0', 'Tea garden'));
    }

    public function test_itinerary_is_scoped_to_the_published_tour(): void
    {
        [$tour] = $this->readyTour('p3f3b2-own');
        [$other] = $this->readyTour('p3f3b2-other');
        $this->day($tour, 1, ['title' => 'My day']);
        $this->day($other, 1, ['title' => 'Different tour day']);
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->has('tour.itinerary', 1)
            ->where('tour.itinerary.0.title', 'My day'));
    }

    public function test_itinerary_list_is_bounded_to_sixty_days(): void
    {
        [$tour] = $this->readyTour('p3f3b2-day-cap');
        for ($day = 1; $day <= 65; $day++) {
            $this->day($tour, $day);
        }
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->has('tour.itinerary', 60)
            ->where('tour.itinerary.0.day_number', 1)
            ->where('tour.itinerary.59.day_number', 60));
    }

    public function test_only_open_future_unexpired_departures_are_visible(): void
    {
        [$tour, $initial] = $this->readyTour('p3f3b2-dates');
        $initial->update(['status' => 'closed']);
        $this->departure($tour, 'hidden-draft', ['status' => 'draft']);
        $this->departure($tour, 'hidden-past', ['starts_at' => now('UTC')->subDay()->format('Y-m-d H:i:s')]);
        $this->departure($tour, 'hidden-expired', ['booking_closes_at' => now('UTC')->subDay()->format('Y-m-d H:i:s')]);
        $this->departure($tour, 'open-future', ['name' => 'December Journey']);

        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->has('tour.upcoming_departures', 1)
            ->where('tour.upcoming_departures.0.name', 'December Journey')
            ->where('tour.upcoming_departures.0.timezone', 'Asia/Dhaka'));
    }

    public function test_departure_preview_does_not_expose_internal_inventory_or_prices(): void
    {
        [$tour] = $this->readyTour('p3f3b2-inventory');
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->has('tour.upcoming_departures', 1)
            ->missing('tour.upcoming_departures.0.id')
            ->missing('tour.upcoming_departures.0.code')
            ->missing('tour.upcoming_departures.0.capacity_total')
            ->missing('tour.upcoming_departures.0.seats_committed')
            ->missing('tour.upcoming_departures.0.booking_closes_at')
            ->missing('tour.upcoming_departures.0.internal_notes')
            ->missing('tour.upcoming_departures.0.meeting_point')
            ->missing('tour.price')
            ->missing('tour.departures'));
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_departure_times_are_utc_iso_and_end_time_nullable(): void
    {
        [$tour, $initial] = $this->readyTour('p3f3b2-timezone');
        $initial->update(['starts_at' => '2030-04-25 04:30:00', 'ends_at' => null, 'booking_closes_at' => null]);
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.upcoming_departures.0.starts_at_utc', '2030-04-25T04:30:00Z')
            ->where('tour.upcoming_departures.0.ends_at_utc', null)
            ->where('tour.upcoming_departures.0.timezone', 'Asia/Dhaka'));
    }

    public function test_previews_are_bounded_ordered_and_come_only_from_selected_tour(): void
    {
        [$tour, $initial] = $this->readyTour('p3f3b2-cap');
        [$other] = $this->readyTour('p3f3b2-cap-other');
        $initial->update(['status' => 'closed']);
        $this->departure($other, 'not-mine', ['name' => 'OTHER TOUR SECRET']);
        for ($n = 1; $n <= 12; $n++) {
            $this->departure($tour, 'future-'.$n, [
                'name' => 'Departure '.$n,
                'starts_at' => now('UTC')->addDays(70 + $n)->format('Y-m-d H:i:s'),
            ]);
        }
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->has('tour.upcoming_departures', 8)
            ->where('tour.upcoming_departures.0.name', 'Departure 1')
            ->where('tour.upcoming_departures.7.name', 'Departure 8'));
    }

    public function test_unpublished_tour_is_not_readable_even_with_itinerary_or_departures(): void
    {
        [$tour] = $this->readyTour('p3f3b2-private');
        $this->day($tour, 1, ['title' => 'Hidden schedule']);
        $tour->update(['status' => 'draft', 'published_at' => null]);
        $this->get('/tours/'.$tour->slug)->assertNotFound();
        self::assertNull(app(PublishedTourDetail::class)->find((string) $tour->slug));
    }

    public function test_visitor_preview_never_creates_a_reservation_or_payment(): void
    {
        [$tour] = $this->readyTour('p3f3b2-nonbinding');
        $this->day($tour, 1);
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.enquiry_url', route('booking-requests.create', ['tour' => $tour->slug]))
            ->has('tour.itinerary', 1)
            ->has('tour.upcoming_departures', 1));
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
        $this->assertDatabaseCount('travel_tour_booking_requests', 0);
    }
}
