<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingLifecycleManager;
use App\Domain\Travel\Bookings\TourBookingQuoteAcceptanceManager;
use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Models\Travel\TourBooking;
use App\Models\Travel\TourBookingRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bablys\TourBookingQaTestCase;

/**
 * P3H.5: full verified-customer -> accepted quote -> authorized staff ->
 * public inventory/customer history integration. No payment is collected.
 * This class intentionally protects the test DB BEFORE RefreshDatabase.
 */
final class BookingCustomerAdminReleaseJourneyTest extends TourBookingQaTestCase
{
    protected function setUp(): void
    {
        $env = (string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? ''));
        $connection = (string) (getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? ''));
        $database = (string) (getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? ''));
        if ($env !== 'testing' || $connection !== 'mysql'
            || preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*_test\z/D', $database) !== 1
            || str_ends_with($database, 'race_test')) {
            throw new \RuntimeException('P3H.5 requires APP_ENV=testing and a dedicated, non-race MySQL *_test database.');
        }
        parent::setUp();
    }

    /**
     * Use only the existing quote approval and staff HTTP conversion contracts.
     *
     * @return array{0:mixed,1:mixed,2:User,3:User,4:TourBooking,5:TourBookingRequest}
     */
    private function converted(string $slug, int $adults = 2): array
    {
        [$tour, $departure] = $this->readyTour($slug);
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $staff = $this->staff('administrator');
        $requestRow = app(TourBookingRequestManager::class)->createCustomer(
            $customer, $tour, $departure, 'standard-bdt', $adults, 0
        )->request;
        $quotes = app(TourBookingQuoteAcceptanceManager::class);
        $preview = $quotes->previewCustomer($customer, $requestRow);
        $quotes->acceptCustomer(
            $customer, $requestRow, (int) $preview['revision'], (string) $preview['fingerprint']
        );
        $this->actingAs($staff)
            ->post('/admin/booking-requests/'.$requestRow->reference_key.'/convert')
            ->assertRedirect();
        $linked = $requestRow->fresh();
        self::assertNotNull($linked);
        self::assertSame('converted', $linked->status);
        self::assertNotNull($linked->booking_id);
        $booking = TourBooking::query()->findOrFail((int) $linked->booking_id);
        return [$tour, $departure, $customer, $staff, $booking, $linked];
    }

    public function test_quote_review_before_approval_is_nonbinding_across_public_and_customer_pages(): void
    {
        [$tour, $departure] = $this->readyTour('h5-quote-preview');
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $row = app(TourBookingRequestManager::class)->createCustomer(
            $customer, $tour, $departure, 'standard-bdt', 2, 0
        )->request;
        $this->actingAs($customer)
            ->postJson('/account/booking-requests/'.$row->reference_key.'/quote-review')
            ->assertOk()->assertJsonPath('quote.nonbinding', true);
        $this->get('/account/travel')->assertOk()->assertInertia(fn ($page) => $page
            ->where('summary.requests', 1)->where('summary.bookings', 0));
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.upcoming_departures.0.seat_summary.available_seats', 10)
            ->where('tour.upcoming_departures.0.seat_summary.pending_seats', 0));
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_approved_conversion_updates_admin_customer_and_public_with_one_commit(): void
    {
        [$tour, $departure, $customer, $staff, $booking] = $this->converted('h5-converted', 2);
        self::assertSame('pending_confirmation', $booking->status);
        self::assertSame('not_collected', $booking->payment_state);
        self::assertSame(2, (int) $departure->fresh()->seats_committed);
        $this->actingAs($customer)->get('/account/bookings')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('bookings', 1)->where('bookings.0.reference', (string) $booking->reference_key)
                ->where('bookings.0.status', 'pending_confirmation'));
        $this->actingAs($staff)->get('/admin/bookings')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('metrics.pending_bookings', 1)->where('metrics.pending_seats', 2));
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.upcoming_departures.0.seat_summary.pending_seats', 2)
            ->where('tour.upcoming_departures.0.seat_summary.confirmed_seats', 0)
            ->where('tour.upcoming_departures.0.seat_summary.available_seats', 8));
        $this->assertDatabaseCount('travel_tour_bookings', 1);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 1);
    }

    public function test_admin_confirmation_updates_customer_history_and_public_without_second_allocation(): void
    {
        [$tour, $departure, $customer, $staff, $booking] = $this->converted('h5-confirm-once', 3);
        $url = '/admin/bookings/'.$booking->reference_key.'/confirm';
        $this->actingAs($staff)->post($url)->assertRedirect();
        $this->post($url)->assertRedirect();
        self::assertSame('confirmed', $booking->fresh()?->status);
        self::assertSame(3, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 1);
        self::assertSame(1, DB::table('cms_audit_events')->where('action', 'tour.booking.confirmed')->count());
        $this->actingAs($customer)->get('/account/travel')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.confirmed', 1)->where('summary.pending', 0)
                ->where('recent_bookings.0.status', 'confirmed'));
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.upcoming_departures.0.seat_summary.confirmed_seats', 3)
            ->where('tour.upcoming_departures.0.seat_summary.confirmed_bookings', 1)
            ->where('tour.upcoming_departures.0.seat_summary.pending_seats', 0)
            ->where('tour.upcoming_departures.0.seat_summary.available_seats', 7));
    }

    public function test_authorized_cancellation_releases_once_and_preserves_customer_history(): void
    {
        [$tour, $departure, $customer, $staff, $booking] = $this->converted('h5-cancel-once');
        $this->actingAs($staff)->post('/admin/bookings/'.$booking->reference_key.'/confirm')->assertRedirect();
        $url = '/admin/bookings/'.$booking->reference_key.'/cancel';
        $this->post($url)->assertRedirect();
        $this->post($url)->assertRedirect();
        self::assertSame('cancelled', $booking->fresh()?->status);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        self::assertSame(1, DB::table('cms_audit_events')->where('action', 'tour.booking.cancelled')->count());
        $this->actingAs($customer)->get('/account/bookings/'.$booking->reference_key)
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('booking.status', 'cancelled'));
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.upcoming_departures.0.seat_summary.confirmed_seats', 0)
            ->where('tour.upcoming_departures.0.seat_summary.pending_seats', 0)
            ->where('tour.upcoming_departures.0.seat_summary.available_seats', 10));
    }

    public function test_two_customer_bookings_keep_confirmed_pending_and_available_totals_distinct(): void
    {
        [$tour, $departure] = $this->readyTour('h5-mixed-inventory');
        $staff = $this->staff('administrator');
        $first = app(TourBookingLifecycleManager::class)->createInternal(
            $staff, $tour, $departure, 'BOOK-h5-mixed-01', 'standard-bdt', 2
        );
        $second = app(TourBookingLifecycleManager::class)->createInternal(
            $staff, $tour, $departure, 'BOOK-h5-mixed-02', 'standard-bdt', 1
        );
        $this->actingAs($staff)->post('/admin/bookings/'.$first->reference_key.'/confirm')->assertRedirect();
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.upcoming_departures.0.seat_summary.confirmed_seats', 2)
            ->where('tour.upcoming_departures.0.seat_summary.pending_seats', 1)
            ->where('tour.upcoming_departures.0.seat_summary.confirmed_bookings', 1)
            ->where('tour.upcoming_departures.0.seat_summary.pending_bookings', 1)
            ->where('tour.upcoming_departures.0.seat_summary.available_seats', 7));
        $this->post('/admin/bookings/'.$second->reference_key.'/cancel')->assertRedirect();
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.upcoming_departures.0.seat_summary.confirmed_seats', 2)
            ->where('tour.upcoming_departures.0.seat_summary.pending_seats', 0)
            ->where('tour.upcoming_departures.0.seat_summary.available_seats', 8));
        self::assertSame(2, (int) $departure->fresh()->seats_committed);
    }

    public function test_historical_booking_price_is_read_only_despite_later_tour_title_change(): void
    {
        [$tour, $departure, $customer, $staff, $booking] = $this->converted('h5-snapshot', 1);
        $originalTitle = (string) $booking->tour_title_snapshot;
        $originalTotal = (int) $booking->total_minor;
        $tour->update(['title' => 'Future Catalog Title']);
        $this->actingAs($customer)->get('/account/bookings/'.$booking->reference_key)->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('booking.tour_title', $originalTitle)
                ->where('booking.total_minor', $originalTotal)
                ->where('booking.payment_state', 'not_collected')
                ->missing('booking.seat_allocation_id')
                ->missing('booking.price_lines_snapshot'));
        $this->actingAs($staff)->get('/admin/bookings/'.$booking->reference_key)->assertOk()
            ->assertInertia(fn ($page) => $page->where('booking.total_minor', $originalTotal));
        self::assertSame(1, (int) $departure->fresh()->seats_committed);
    }

    public function test_malicious_post_body_cannot_change_booking_price_seats_or_payment(): void
    {
        [$tour, $departure, $customer, $staff, $booking] = $this->converted('h5-tamper', 2);
        $total = (int) $booking->total_minor;
        $this->actingAs($staff)->post('/admin/bookings/'.$booking->reference_key.'/confirm', [
            'total_minor' => 1,
            'seats' => 999,
            'payment_state' => 'paid',
            'status' => 'confirmed',
            'created_by' => 999,
        ])->assertRedirect();
        self::assertSame('confirmed', $booking->fresh()?->status);
        self::assertSame('not_collected', $booking->fresh()?->payment_state);
        self::assertSame($total, (int) $booking->fresh()?->total_minor);
        self::assertSame(2, (int) $booking->fresh()?->seats);
        self::assertSame(2, (int) $departure->fresh()->seats_committed);
        $this->actingAs($customer)->get('/account/bookings/'.$booking->reference_key)->assertOk()
            ->assertInertia(fn ($page) => $page->where('booking.total_minor', $total));
    }

    public function test_repeated_public_and_customer_reads_do_not_change_booking_state_or_seats(): void
    {
        [$tour, $departure, $customer, $staff, $booking] = $this->converted('h5-readonly', 1);
        $before = (int) $departure->fresh()->seats_committed;
        for ($i = 0; $i < 3; $i++) {
            $this->get('/tours/'.$tour->slug)->assertOk();
            $this->get('/tours/'.$tour->slug.'/booking-request')->assertOk();
            $this->actingAs($customer)->get('/account/travel')->assertOk();
            $this->get('/account/bookings')->assertOk();
            $this->get('/account/bookings/'.$booking->reference_key)->assertOk();
            $this->actingAs($staff)->get('/admin/bookings')->assertOk();
        }
        self::assertSame($before, (int) $departure->fresh()->seats_committed);
        self::assertSame('pending_confirmation', $booking->fresh()?->status);
        $this->assertDatabaseCount('travel_tour_bookings', 1);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 1);
    }
}
