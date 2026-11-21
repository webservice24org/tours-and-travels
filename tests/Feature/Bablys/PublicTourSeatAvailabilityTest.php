<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingLifecycleManager;
use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Models\User;
use Tests\Support\Bablys\TourBookingQaTestCase;

/**
 * P3H.3 integration: public counts are read-only snapshots of trusted
 * booking/seat-allocation state, never traveler submissions or private props.
 */
final class PublicTourSeatAvailabilityTest extends TourBookingQaTestCase
{
    protected function setUp(): void
    {
        $env = (string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? ''));
        $connection = (string) (getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? ''));
        $database = (string) (getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? ''));
        if ($env !== 'testing' || $connection !== 'mysql'
            || preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*_test\z/D', $database) !== 1
            || str_ends_with($database, 'race_test')) {
            throw new \RuntimeException('P3H.3 requires APP_ENV=testing and a dedicated non-race MySQL *_test database.');
        }
        parent::setUp();
    }

    public function test_public_detail_shows_zero_bookings_and_total_available_capacity(): void
    {
        [$tour] = $this->readyTour('p3h3-empty');
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.upcoming_departures.0.seat_summary.total_seats', 10)
            ->where('tour.upcoming_departures.0.seat_summary.confirmed_seats', 0)
            ->where('tour.upcoming_departures.0.seat_summary.pending_seats', 0)
            ->where('tour.upcoming_departures.0.seat_summary.available_seats', 10)
            ->where('tour.upcoming_departures.0.seat_summary.confirmed_bookings', 0)
            ->missing('tour.upcoming_departures.0.id')
            ->missing('tour.upcoming_departures.0.seats_committed')
            ->missing('tour.upcoming_departures.0.capacity_total'));
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_pending_booking_reduces_available_seats_without_increasing_confirmed(): void
    {
        [$tour, $departure] = $this->readyTour('p3h3-pending');
        $staff = $this->staff('administrator');
        app(TourBookingLifecycleManager::class)->createInternal($staff, $tour, $departure, 'BOOK-p3h3-pending', 'standard-bdt', 2);
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.upcoming_departures.0.seat_summary.confirmed_seats', 0)
            ->where('tour.upcoming_departures.0.seat_summary.pending_seats', 2)
            ->where('tour.upcoming_departures.0.seat_summary.pending_bookings', 1)
            ->where('tour.upcoming_departures.0.seat_summary.available_seats', 8));
        self::assertSame(2, (int) $departure->fresh()->seats_committed);
    }

    public function test_confirmation_moves_pending_to_confirmed_without_second_inventory_deduction(): void
    {
        [$tour, $departure] = $this->readyTour('p3h3-confirm');
        $staff = $this->staff('administrator');
        $manager = app(TourBookingLifecycleManager::class);
        $booking = $manager->createInternal($staff, $tour, $departure, 'BOOK-p3h3-confirm', 'standard-bdt', 3);
        $this->actingAs($staff)->post('/admin/bookings/'.$booking->reference_key.'/confirm')->assertRedirect();
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.upcoming_departures.0.seat_summary.confirmed_seats', 3)
            ->where('tour.upcoming_departures.0.seat_summary.confirmed_bookings', 1)
            ->where('tour.upcoming_departures.0.seat_summary.pending_seats', 0)
            ->where('tour.upcoming_departures.0.seat_summary.available_seats', 7));
        self::assertSame(3, (int) $departure->fresh()->seats_committed);
        $this->post('/admin/bookings/'.$booking->reference_key.'/confirm')->assertRedirect();
        self::assertSame(3, (int) $departure->fresh()->seats_committed);
    }

    public function test_cancellation_updates_public_remaining_and_removes_confirmed_count(): void
    {
        [$tour, $departure] = $this->readyTour('p3h3-cancel');
        $staff = $this->staff('administrator');
        $manager = app(TourBookingLifecycleManager::class);
        $booking = $manager->createInternal($staff, $tour, $departure, 'BOOK-p3h3-cancel', 'standard-bdt', 2);
        $manager->confirm($staff, $booking);
        $manager->cancel($staff, $booking);
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.upcoming_departures.0.seat_summary.confirmed_seats', 0)
            ->where('tour.upcoming_departures.0.seat_summary.pending_seats', 0)
            ->where('tour.upcoming_departures.0.seat_summary.available_seats', 10));
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
    }

    public function test_booking_count_is_number_of_bookings_not_number_of_traveler_seats(): void
    {
        [$tour, $departure] = $this->readyTour('p3h3-multi');
        $staff = $this->staff('administrator');
        $manager = app(TourBookingLifecycleManager::class);
        $first = $manager->createInternal($staff, $tour, $departure, 'BOOK-p3h3-multi-1', 'standard-bdt', 1);
        $second = $manager->createInternal($staff, $tour, $departure, 'BOOK-p3h3-multi-2', 'standard-bdt', 3);
        $manager->confirm($staff, $first);
        $manager->confirm($staff, $second);
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.upcoming_departures.0.seat_summary.confirmed_seats', 4)
            ->where('tour.upcoming_departures.0.seat_summary.confirmed_bookings', 2)
            ->where('tour.upcoming_departures.0.seat_summary.available_seats', 6));
    }

    public function test_booking_request_form_shows_matching_public_counts_for_requestable_departure(): void
    {
        [$tour, $departure] = $this->readyTour('p3h3-form');
        $staff = $this->staff('administrator');
        app(TourBookingLifecycleManager::class)->createInternal($staff, $tour, $departure, 'BOOK-p3h3-form', 'standard-bdt', 2);
        $this->get('/tours/'.$tour->slug.'/booking-request')->assertOk()->assertInertia(fn ($page) => $page
            ->where('nonbinding', true)
            ->where('departures.0.seat_summary.total_seats', 10)
            ->where('departures.0.seat_summary.pending_seats', 2)
            ->where('departures.0.seat_summary.available_seats', 8)
            ->missing('departures.0.internal_notes')
            ->missing('departures.0.meeting_point')
            ->missing('departures.0.booking_closes_at')
            ->missing('departures.0.seats_committed'));
        self::assertSame(2, (int) $departure->fresh()->seats_committed);
    }

    public function test_other_committed_inventory_is_not_mistaken_for_available_capacity(): void
    {
        [$tour, $departure] = $this->readyTour('p3h3-external-hold');
        // A legacy allocation can hold inventory outside the TourBooking table.
        $departure->forceFill(['seats_committed' => 4])->save();
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.upcoming_departures.0.seat_summary.confirmed_seats', 0)
            ->where('tour.upcoming_departures.0.seat_summary.pending_seats', 0)
            ->where('tour.upcoming_departures.0.seat_summary.other_committed_seats', 4)
            ->where('tour.upcoming_departures.0.seat_summary.available_seats', 6));
    }

    public function test_sold_out_open_departure_shows_zero_public_availability_but_cannot_be_requested(): void
    {
        [$tour, $departure] = $this->readyTour('p3h3-sold-out');
        $departure->forceFill(['seats_committed' => (int) $departure->capacity_total])->save();
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->has('tour.upcoming_departures', 1)
            ->where('tour.upcoming_departures.0.seat_summary.available_seats', 0));
        $this->get('/tours/'.$tour->slug.'/booking-request')->assertOk()->assertInertia(fn ($page) => $page
            ->has('departures', 0));
        self::assertSame(10, (int) $departure->fresh()->seats_committed);
    }

    public function test_departure_counts_are_isolated_from_other_published_tours(): void
    {
        [$tour] = $this->readyTour('p3h3-scoped');
        [$other, $departure] = $this->readyTour('p3h3-foreign');
        $staff = $this->staff('administrator');
        app(TourBookingLifecycleManager::class)->createInternal($staff, $other, $departure, 'BOOK-p3h3-foreign', 'standard-bdt', 4);
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.upcoming_departures.0.seat_summary.pending_seats', 0)
            ->where('tour.upcoming_departures.0.seat_summary.available_seats', 10));
    }

    public function test_unpublished_and_closed_or_expired_departures_never_expose_counts(): void
    {
        [$draft] = $this->readyTour('p3h3-draft');
        $draft->update(['status' => 'draft', 'published_at' => null]);
        $this->get('/tours/'.$draft->slug)->assertNotFound();
        $this->get('/tours/'.$draft->slug.'/booking-request')->assertNotFound();

        [$tour, $departure] = $this->readyTour('p3h3-expired');
        $departure->forceFill(['booking_closes_at' => now('UTC')->subHour()->format('Y-m-d H:i:s')])->save();
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page->has('tour.upcoming_departures', 0));
        $this->get('/tours/'.$tour->slug.'/booking-request')->assertOk()->assertInertia(fn ($page) => $page->has('departures', 0));
    }

    public function test_repeated_public_gets_never_create_or_mutate_bookings(): void
    {
        [$tour, $departure] = $this->readyTour('p3h3-readonly');
        $before = (int) $departure->seats_committed;
        for ($i = 0; $i < 3; $i++) {
            $this->get('/tours/'.$tour->slug)->assertOk();
            $this->get('/tours/'.$tour->slug.'/booking-request')->assertOk();
        }
        self::assertSame($before, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_unconverted_booking_request_does_not_affect_seat_counts(): void
    {
        [$tour, $departure] = $this->readyTour('p3h3-request-only');
        $customer = User::factory()->create(['email_verified_at' => now()]);
        app(TourBookingRequestManager::class)->createCustomer(
            $customer, $tour, $departure, 'standard-bdt', 2, 0
        );
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.upcoming_departures.0.seat_summary.pending_seats', 0)
            ->where('tour.upcoming_departures.0.seat_summary.confirmed_seats', 0)
            ->where('tour.upcoming_departures.0.seat_summary.available_seats', 10));
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_frontend_renders_public_aggregate_only_without_staff_sidebar(): void
    {
        $detail = file_get_contents(base_path('resources/js/pages/public/tours/show.tsx'));
        $form = file_get_contents(base_path('resources/js/pages/public/booking-requests/create.tsx'));
        self::assertIsString($detail);
        self::assertIsString($form);
        foreach ([$detail, $form] as $tsx) {
            self::assertStringContainsString('Available seats', $tsx);
            self::assertStringContainsString('Confirmed seats', $tsx);
            self::assertStringContainsString('Pending seats', $tsx);
            self::assertStringContainsString('PublicSiteLayout', $tsx);
            self::assertStringNotContainsString('AppSidebar', $tsx);
            self::assertStringNotContainsString('booking.reference_key', $tsx);
        }
        self::assertStringContainsString('rel="canonical"', $detail);
        self::assertStringContainsString('encodeURIComponent(departure.starts_at_utc)', $detail);
        self::assertStringContainsString('No seat is reserved', $form);
        self::assertStringContainsString('Currently fully booked', $detail);
    }
}
