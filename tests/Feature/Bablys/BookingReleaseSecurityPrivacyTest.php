<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingLifecycleManager;
use App\Domain\Travel\Bookings\TourBookingQuoteAcceptanceManager;
use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Models\Travel\TourBooking;
use App\Models\Travel\TourPriceVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bablys\TourBookingQaTestCase;

/** P3H.5: authorization, owner-only access, private delivery and invariant refusal. */
final class BookingReleaseSecurityPrivacyTest extends TourBookingQaTestCase
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

    /** @return array{0:mixed,1:mixed,2:User,3:TourBooking} */
    private function pending(string $slug, int $adults = 2): array
    {
        [$tour, $departure] = $this->readyTour($slug);
        $staff = $this->staff('administrator');
        $booking = app(TourBookingLifecycleManager::class)->createInternal(
            $staff, $tour, $departure, 'BOOK-'.$slug.'-001', 'standard-bdt', $adults
        );
        return [$tour, $departure, $staff, $booking];
    }

    public function test_guest_never_receives_account_history_or_staff_actions(): void
    {
        [$tour, $departure, $staff, $booking] = $this->pending('h5-guest-auth');
        foreach (['/account/travel', '/account/bookings', '/account/bookings/'.$booking->reference_key] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        $this->get('/admin/bookings')->assertRedirect(route('login'));
        $this->post('/admin/bookings/'.$booking->reference_key.'/confirm')
            ->assertRedirect(route('login'));
        self::assertSame('pending_confirmation', $booking->fresh()?->status);
        self::assertSame(2, (int) $departure->fresh()->seats_committed);
    }

    public function test_unverified_customer_and_unprivileged_staff_never_see_private_booking_data(): void
    {
        [$tour, $departure, $staff, $booking] = $this->pending('h5-unverified');
        $this->actingAs(User::factory()->create(['email_verified_at' => null]))
            ->get('/account/bookings')->assertRedirect();
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->get('/admin/bookings/'.$booking->reference_key)->assertForbidden();
        $this->actingAs($this->staff('content_editor'))
            ->post('/admin/bookings/'.$booking->reference_key.'/confirm')->assertForbidden();
        $this->actingAs($this->staff('review_moderator'))
            ->get('/admin/bookings')->assertForbidden();
        self::assertSame('pending_confirmation', $booking->fresh()?->status);
    }

    public function test_customer_cannot_read_another_customers_history_or_mutate_staff_bookings(): void
    {
        [$tour, $departure] = $this->readyTour('h5-cross-customer');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $staff = $this->staff('administrator');
        $row = app(TourBookingRequestManager::class)->createCustomer(
            $owner, $tour, $departure, 'standard-bdt', 1, 0
        )->request;
        $booking = app(TourBookingLifecycleManager::class)->createInternal(
            $staff, $tour, $departure, 'BOOK-h5-cross-001', 'standard-bdt', 1
        );
        $row->booking_id = (int) $booking->getKey();
        $row->status = 'converted';
        $row->save();
        $this->actingAs($owner)->get('/account/bookings/'.$booking->reference_key)->assertOk();
        $this->actingAs($other)->get('/account/bookings')->assertOk()
            ->assertInertia(fn ($page) => $page->has('bookings', 0));
        $this->get('/account/bookings/'.$booking->reference_key)->assertNotFound();
        $this->post('/admin/bookings/'.$booking->reference_key.'/confirm')->assertForbidden();
        self::assertSame(1, (int) $departure->fresh()->seats_committed);
        self::assertSame('pending_confirmation', $booking->fresh()?->status);
    }

    public function test_guest_booking_with_matching_email_is_not_assigned_to_customer_account(): void
    {
        [$tour, $departure, $staff] = $this->pending('h5-guest-email-fixture', 1);
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $guest = app(TourBookingRequestManager::class)->createGuest(
            $tour, $departure, 'standard-bdt', 1, 0, 'Guest Match', (string) $customer->email
        );
        $booking = app(TourBookingLifecycleManager::class)->createInternal(
            $staff, $tour, $departure, 'BOOK-h5-guest-email-002', 'standard-bdt', 1
        );
        DB::table('travel_tour_booking_requests')->where('id', $guest->request->getKey())
            ->update(['booking_id' => $booking->getKey(), 'status' => 'converted']);
        $this->actingAs($customer)->get('/account/bookings')->assertOk()
            ->assertInertia(fn ($page) => $page->has('bookings', 0));
        $this->get('/account/bookings/'.$booking->reference_key)->assertNotFound();
    }

    public function test_unapproved_or_repriced_request_cannot_allocate_seats(): void
    {
        [$tour, $departure] = $this->readyTour('h5-unapproved');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $staff = $this->staff('administrator');
        $row = app(TourBookingRequestManager::class)->createCustomer(
            $owner, $tour, $departure, 'standard-bdt', 1, 0
        )->request;
        $endpoint = '/admin/booking-requests/'.$row->reference_key.'/convert';
        $this->actingAs($staff)->post($endpoint)->assertSessionHasErrors();
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $quotes = app(TourBookingQuoteAcceptanceManager::class);
        $preview = $quotes->previewCustomer($owner, $row);
        $quotes->acceptCustomer($owner, $row, (int) $preview['revision'], (string) $preview['fingerprint']);
        $price = TourPriceVariant::query()->where('tour_id', $tour->getKey())
            ->where('code', 'standard-bdt')->firstOrFail();
        $price->rates()->where('traveler_type', 'adult')->update(['regular_minor' => 1999000]);
        $this->post($endpoint)->assertSessionHasErrors();
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        self::assertSame('verified', $row->fresh()?->status);
    }

    public function test_paid_booking_cannot_be_cancelled_or_release_inventory_without_refund_policy(): void
    {
        [$tour, $departure, $staff, $booking] = $this->pending('h5-paid-reject', 2);
        DB::table('travel_tour_bookings')->where('id', $booking->getKey())
            ->update(['payment_state' => 'paid']);
        $this->actingAs($staff)->post('/admin/bookings/'.$booking->reference_key.'/cancel')
            ->assertSessionHasErrors();
        self::assertSame('pending_confirmation', $booking->fresh()?->status);
        self::assertSame(2, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 1);
    }

    public function test_admin_and_customer_views_are_noindex_and_private_without_secrets(): void
    {
        [$tour, $departure, $staff, $booking] = $this->pending('h5-private-headers', 1);
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $row = app(TourBookingRequestManager::class)->createCustomer(
            $customer, $tour, $departure, 'standard-bdt', 1, 0
        )->request;
        $row->booking_id = (int) $booking->getKey();
        $row->status = 'converted';
        $row->save();
        foreach ([
            [$staff, '/admin/bookings'], [$staff, '/admin/bookings/'.$booking->reference_key],
            [$customer, '/account/travel'], [$customer, '/account/bookings'],
            [$customer, '/account/bookings/'.$booking->reference_key],
        ] as [$actor, $url]) {
            $response = $this->actingAs($actor)->get($url)->assertOk()
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
                ->assertDontSee('access_token_hash')
                ->assertDontSee('verification_token_hash')
                ->assertDontSee('contact_email_encrypted')
                ->assertDontSee('seat_allocation_id');
            $directives = array_map('trim', explode(',', (string) $response->baseResponse->headers->get('Cache-Control')));
            self::assertEqualsCanonicalizing(['private', 'no-store', 'max-age=0'], $directives);
        }
    }

    public function test_public_sold_out_departure_is_not_requestable_even_while_open(): void
    {
        [$tour, $departure] = $this->readyTour('h5-soldout');
        // A fully committed departure remains unavailable, regardless of
        // whether each committed seat is attached to a current booking.
        $departure->forceFill(['seats_committed' => (int) $departure->capacity_total])->save();
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.upcoming_departures.0.seat_summary.available_seats', 0));
        $this->get('/tours/'.$tour->slug.'/booking-request')->assertOk()
            ->assertInertia(fn ($page) => $page->has('departures', 0));
        self::assertSame(10, (int) $departure->fresh()->seats_committed);
    }
}
