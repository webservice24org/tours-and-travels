<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingLifecycleManager;
use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Mail\GuestBookingRequestLinksMail;
use App\Models\Travel\TourBookingRequest;
use App\Models\Travel\TourItineraryDay;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bablys\TourBookingQaTestCase;

/**
 * P3G: multi-request, cross-feature HTTP journeys on an isolated test database.
 * This suite MUST NOT run in the dedicated real-MySQL race database.
 */
final class PublicTourReleaseJourneyTest extends TourBookingQaTestCase
{
    /** Fail before RefreshDatabase can migrate the wrong database. */
    protected function setUp(): void
    {
        $env = (string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? ''));
        $connection = (string) (getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? ''));
        $database = (string) (getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? ''));
        if ($env !== 'testing' || $connection !== 'mysql'
            || preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*_test\z/D', $database) !== 1
            || str_ends_with($database, 'race_test')) {
            throw new \RuntimeException('P3G requires APP_ENV=testing, DB_CONNECTION=mysql, and a dedicated NON-race *_test database.');
        }
        parent::setUp();
    }

    public function test_listing_detail_departure_enquiry_and_server_quote_are_one_nonbinding_journey(): void
    {
        [$tour, $departure] = $this->readyTour('p3g-complete-journey');
        $hint = str_replace(' ', 'T', (string) $departure->starts_at).'Z';
        $detail = route('public.tours.show', ['slug' => $tour->slug]);

        $this->get('/tours')->assertOk()->assertInertia(fn ($page) => $page
            ->component('public/tours/index')->has('tours', 1)
            ->where('tours.0.slug', (string) $tour->slug)
            ->where('seo.robots', 'index, follow'));
        $this->get($detail)->assertOk()->assertInertia(fn ($page) => $page
            ->component('public/tours/show')
            ->where('tour.canonical_url', $detail)
            ->where('tour.upcoming_departures.0.starts_at_utc', $hint)
            ->where('tour.enquiry_url', route('booking-requests.create', ['tour' => $tour->slug]))
            ->missing('tour.upcoming_departures.0.id')
            ->missing('tour.upcoming_departures.0.capacity_total'));
        $this->get('/tours/'.$tour->slug.'/booking-request?departure_at='.rawurlencode($hint))
            ->assertOk()->assertInertia(fn ($page) => $page
                ->component('public/booking-requests/create')
                ->where('departure_selection.requested', true)
                ->where('departure_selection.selected_id', (int) $departure->getKey())
                ->where('nonbinding', true));
        $this->postJson('/booking-requests/quote', [
            'tour_id' => (int) $tour->getKey(), 'departure_id' => (int) $departure->getKey(),
            'variant_code' => 'standard-bdt', 'adults' => 1, 'children' => 0,
            'total_minor' => 1, 'currency' => 'USD',
        ])->assertOk()->assertJsonPath('quote.currency', 'BDT')
            ->assertJsonPath('inventory_reserved', false)->assertJsonPath('nonbinding', true);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_booking_requests', 0);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_taxonomy_search_sort_and_canonical_hand_off_to_same_published_detail(): void
    {
        [$tour] = $this->readyTour('p3g-search-journey');
        $tour->update(['title' => 'River Heritage Discovery']);
        $url = '/tours?q=Heritage&category=p3g-search-journey-category'
            .'&destination=p3g-search-journey-destination&sort=title_asc';
        $this->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow')
            ->assertInertia(fn ($page) => $page
                ->has('tours', 1)->where('tours.0.slug', (string) $tour->slug)
                ->where('seo.robots', 'noindex, follow')
                ->where('seo.canonical_url', route('public.tours.index')));
        $this->get('/tours/'.$tour->slug.'?utm_campaign=sensitive')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tour.canonical_url', route('public.tours.show', ['slug' => $tour->slug]))
                ->where('tour.title', 'River Heritage Discovery'));
    }

    public function test_publication_revocation_removes_detail_and_enquiry_after_prior_success(): void
    {
        [$tour] = $this->readyTour('p3g-revoked-after-browse');
        $this->get('/tours/'.$tour->slug)->assertOk();
        $this->get('/tours/'.$tour->slug.'/booking-request')->assertOk();
        $tour->update(['status' => 'draft', 'published_at' => null]);
        $this->get('/tours/'.$tour->slug)->assertNotFound();
        $this->get('/tours/'.$tour->slug.'/booking-request')->assertNotFound();
        $this->get('/tours?q=p3g-revoked-after-browse')->assertOk()
            ->assertInertia(fn ($page) => $page->has('tours', 0));
        $this->assertDatabaseCount('travel_tour_booking_requests', 0);
    }

    public function test_unrequestable_departure_after_preview_requires_new_choice(): void
    {
        [$tour, $departure] = $this->readyTour('p3g-closed-between-views');
        $hint = str_replace(' ', 'T', (string) $departure->starts_at).'Z';
        $this->get('/tours/'.$tour->slug)->assertOk()
            ->assertInertia(fn ($page) => $page->has('tour.upcoming_departures', 1));
        $departure->update(['booking_closes_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s')]);
        $this->get('/tours/'.$tour->slug.'/booking-request?departure_at='.rawurlencode($hint))
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('departure_selection.requested', true)
                ->where('departure_selection.selected_id', null)
                ->has('departures', 0)->where('nonbinding', true));
        $this->postJson('/booking-requests/quote', [
            'tour_id' => (int) $tour->getKey(), 'departure_id' => (int) $departure->getKey(),
            'variant_code' => 'standard-bdt', 'adults' => 1, 'children' => 0,
        ])->assertUnprocessable();
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_guest_http_submission_sends_private_links_but_reserves_no_seats(): void
    {
        Mail::fake();
        [$tour, $departure] = $this->readyTour('p3g-guest-mail');
        $this->get('/tours/'.$tour->slug.'/booking-request')->assertOk();
        $this->post('/booking-requests', [
            'tour_id' => (int) $tour->getKey(), 'departure_id' => (int) $departure->getKey(),
            'variant_code' => 'standard-bdt', 'adults' => 1, 'children' => 0,
            'contact_name' => 'Journey Visitor', 'contact_email' => 'journey@example.test',
            'consent' => true, 'submission_id' => (string) Str::uuid(),
        ])->assertRedirect('/booking-requests/submitted');
        Mail::assertSent(GuestBookingRequestLinksMail::class, 1);
        self::assertSame('awaiting_verification', TourBookingRequest::query()->firstOrFail()->status);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_booking_requests', 1);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_owner_scoped_customer_retries_return_single_request_without_reservation(): void
    {
        [$tour, $departure] = $this->readyTour('p3g-customer-retry');
        $customer = User::factory()->create(['email_verified_at' => now('UTC')]);
        $payload = [
            'tour_id' => (int) $tour->getKey(), 'departure_id' => (int) $departure->getKey(),
            'variant_code' => 'standard-bdt', 'adults' => 2, 'children' => 0,
            'consent' => true, 'submission_id' => (string) Str::uuid(),
        ];
        $this->actingAs($customer)->post('/booking-requests', $payload)
            ->assertRedirect('/booking-requests/submitted');
        $this->post('/booking-requests', $payload)->assertRedirect('/booking-requests/submitted');
        $this->assertDatabaseCount('travel_tour_booking_requests', 1);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_customer_quote_acceptance_is_required_before_staff_conversion(): void
    {
        [$tour, $departure] = $this->readyTour('p3g-convert-after-approval');
        $customer = User::factory()->create(['email_verified_at' => now('UTC')]);
        $staff = $this->staff('administrator');
        $row = app(TourBookingRequestManager::class)->createCustomer(
            $customer, $tour, $departure, 'standard-bdt', 1, 0
        )->request;
        $url = '/account/booking-requests/'.$row->reference_key;
        // An unaccepted customer enquiry must not be promoted to a booking.
        try {
            app(TourBookingRequestManager::class)->convertVerified($staff, $row);
            self::fail('Conversion before customer quote acceptance must be rejected.');
        } catch (ValidationException) {
            self::assertSame(0, (int) $departure->fresh()->seats_committed);
            $this->assertDatabaseCount('travel_tour_bookings', 0);
        }
        $response = $this->actingAs($customer)->postJson($url.'/quote-review')
            ->assertOk()->assertJsonPath('quote.nonbinding', true);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $quote = $response->json('quote');
        self::assertIsArray($quote);
        $this->postJson($url.'/quote-accept', [
            'revision' => $quote['revision'], 'fingerprint' => $quote['fingerprint'],
        ])->assertOk()->assertJsonPath('quote.accepted', true);
        $booking = app(TourBookingRequestManager::class)->convertVerified($staff, $row);
        $same = app(TourBookingRequestManager::class)->convertVerified($staff, $row);
        self::assertSame((int) $booking->getKey(), (int) $same->getKey());
        self::assertSame(1, (int) $departure->fresh()->seats_committed);
        self::assertSame('not_collected', $booking->payment_state);
        app(TourBookingLifecycleManager::class)->confirm($staff, $booking);
        self::assertSame('confirmed', $booking->fresh()?->status);
        $this->assertDatabaseCount('travel_tour_bookings', 1);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 1);
    }

    public function test_public_itinerary_and_departure_detail_are_read_only_on_repeated_visits(): void
    {
        [$tour, $departure] = $this->readyTour('p3g-readonly-repeat');
        TourItineraryDay::query()->create([
            'tour_id' => $tour->getKey(), 'day_number' => 1, 'title' => 'Arrival',
            'location_label' => 'Coast', 'description' => 'Meet the group',
            'overnight_location' => 'Resort', 'meals' => ['Dinner'], 'highlights' => ['Sunset'],
        ]);
        for ($n = 0; $n < 3; $n++) {
            $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
                ->where('tour.itinerary.0.title', 'Arrival')
                ->has('tour.upcoming_departures', 1));
            $this->get('/tours/'.$tour->slug.'/booking-request')->assertOk();
        }
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_booking_requests', 0);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }
}
