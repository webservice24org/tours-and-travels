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
use Illuminate\Support\Facades\Route;
use Tests\Support\Bablys\TourBookingQaTestCase;

/** P3H.4 verified-account ownership & historical read-only booking UX. */
final class CustomerTravelAccountTest extends TourBookingQaTestCase
{
    protected function setUp(): void
    {
        $env = (string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? ''));
        $connection = (string) (getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? ''));
        $database = (string) (getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? ''));
        if ($env !== 'testing' || $connection !== 'mysql'
            || preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*_test\z/D', $database) !== 1
            || str_ends_with($database, 'race_test')) {
            throw new \RuntimeException('P3H.4 tests require APP_ENV=testing and a dedicated non-race MySQL *_test database.');
        }
        parent::setUp();
    }

    /** @return array{0:User,1:TourBooking,2:TourBookingRequest} */
    private function linkedCustomer(string $slug): array
    {
        [$tour, $departure] = $this->readyTour($slug);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $requestRow = app(TourBookingRequestManager::class)->createCustomer(
            $owner, $tour, $departure, 'standard-bdt', 2, 0
        )->request;
        $admin = $this->staff('administrator');
        $booking = app(TourBookingLifecycleManager::class)->createInternal(
            $admin, $tour, $departure, 'BOOK-'.$slug.'-001', 'standard-bdt', 2
        );
        $requestRow->booking_id = (int) $booking->getKey();
        $requestRow->status = 'converted';
        $requestRow->save();
        return [$owner, $booking, $requestRow];
    }

    public function test_guest_is_redirected_to_login_from_all_customer_account_pages(): void
    {
        $this->get('/account/travel')->assertRedirect(route('login'));
        $this->get('/account/bookings')->assertRedirect(route('login'));
        $this->get('/account/bookings/BOOK-secret-1234')->assertRedirect(route('login'));
    }

    public function test_unverified_user_does_not_receive_account_data(): void
    {
        $owner = User::factory()->create(['email_verified_at' => null]);
        $this->actingAs($owner)->get('/account/travel')->assertRedirect();
        $this->get('/account/bookings')->assertRedirect();
        $this->get('/account/bookings/BOOK-secret-1234')->assertRedirect();
    }

    public function test_verified_customer_can_view_empty_dashboard_and_booking_history(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($owner)->get('/account/travel')->assertOk()
            ->assertInertia(fn ($page) => $page->component('account/travel/index')
                ->where('summary.requests', 0)->where('summary.bookings', 0)
                ->has('recent_bookings', 0)->has('recent_requests', 0));
        $this->get('/account/bookings')->assertOk()
            ->assertInertia(fn ($page) => $page->component('account/bookings/index')
                ->has('bookings', 0)->where('pagination.total', 0));
    }

    public function test_customer_sees_only_bookings_linked_to_own_customer_requests(): void
    {
        [$owner, $booking] = $this->linkedCustomer('h4-owner');
        [$other, $otherBooking] = $this->linkedCustomer('h4-other');
        $this->actingAs($owner)->get('/account/bookings')->assertOk()
            ->assertInertia(fn ($page) => $page->has('bookings', 1)
                ->where('bookings.0.reference', (string) $booking->reference_key)
                ->where('bookings.0.seats', 2)
                ->where('pagination.total', 1));
        $this->get('/account/bookings/'.$otherBooking->reference_key)->assertNotFound();
        $this->actingAs($other)->get('/account/bookings/'.$booking->reference_key)->assertNotFound();
    }

    public function test_customer_details_show_original_quote_and_own_request_reference_only(): void
    {
        [$owner, $booking, $requestRow] = $this->linkedCustomer('h4-detail');
        $originalTotal = (int) $booking->total_minor;
        $this->actingAs($owner)->get('/account/bookings/'.$booking->reference_key)->assertOk()
            ->assertInertia(fn ($page) => $page->component('account/bookings/show')
                ->where('booking.reference', (string) $booking->reference_key)
                ->where('booking.total_minor', $originalTotal)
                ->where('booking.status', 'pending_confirmation')
                ->where('booking.payment_state', 'not_collected')
                ->where('request_reference', (string) $requestRow->reference_key)
                ->missing('booking.price_lines_snapshot')
                ->missing('booking.seat_allocation_id')
                ->missing('booking.created_by')
                ->missing('booking.confirmation_due_at_utc'));
    }

    public function test_staff_created_booking_without_customer_request_is_not_assigned_by_email(): void
    {
        [$tour, $departure] = $this->readyTour('h4-unlinked');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $staff = $this->staff('administrator');
        $booking = app(TourBookingLifecycleManager::class)->createInternal(
            $staff, $tour, $departure, 'BOOK-h4-unlinked-001', 'standard-bdt', 1
        );
        $this->actingAs($owner)->get('/account/bookings')->assertOk()
            ->assertInertia(fn ($page) => $page->has('bookings', 0));
        $this->get('/account/bookings/'.$booking->reference_key)->assertNotFound();
    }

    public function test_guest_request_with_matching_email_is_not_auto_claimed(): void
    {
        [$tour, $departure] = $this->readyTour('h4-guest');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $guest = app(TourBookingRequestManager::class)->createGuest(
            $tour, $departure, 'standard-bdt', 1, 0, 'Matching Guest', (string) $owner->email
        );
        $staff = $this->staff('administrator');
        $booking = app(TourBookingLifecycleManager::class)->createInternal(
            $staff, $tour, $departure, 'BOOK-h4-guest-001', 'standard-bdt', 1
        );
        DB::table('travel_tour_booking_requests')->where('id', $guest->request->getKey())
            ->update(['booking_id' => $booking->getKey(), 'status' => 'converted']);
        $this->actingAs($owner)->get('/account/travel')->assertOk()
            ->assertInertia(fn ($page) => $page->where('summary.bookings', 0)
                ->has('recent_requests', 0));
        $this->get('/account/bookings/'.$booking->reference_key)->assertNotFound();
    }

    public function test_booking_confirmation_updates_account_status_without_extra_seats(): void
    {
        [$owner, $booking] = $this->linkedCustomer('h4-confirm');
        $staff = $this->staff('administrator');
        $departure = $booking->departure;
        self::assertNotNull($departure);
        $committed = (int) $departure->seats_committed;
        app(TourBookingLifecycleManager::class)->confirm($staff, $booking);
        $this->actingAs($owner)->get('/account/travel')->assertOk()
            ->assertInertia(fn ($page) => $page->where('summary.confirmed', 1)
                ->where('summary.pending', 0)->where('recent_bookings.0.status', 'confirmed'));
        self::assertSame($committed, (int) $departure->fresh()->seats_committed);
    }

    public function test_existing_request_quote_review_interface_is_preserved(): void
    {
        [$tour, $departure] = $this->readyTour('h4-quote');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $row = app(TourBookingRequestManager::class)->createCustomer(
            $owner, $tour, $departure, 'standard-bdt', 1, 0
        )->request;
        $this->actingAs($owner)->get('/account/booking-requests')->assertOk()
            ->assertInertia(fn ($page) => $page->component('account/booking-requests/index')
                ->where('requests.0.reference', (string) $row->reference_key));
        $quotePanel = (string) file_get_contents(base_path('resources/js/pages/account/booking-requests/index.tsx'));
        self::assertStringContainsString('<QuoteApprovalPanel reference={row.reference}', $quotePanel);
        self::assertStringContainsString('CustomerTravelLayout', $quotePanel);
    }

    public function test_account_pages_are_private_noindex_and_never_expose_guest_tokens(): void
    {
        [$owner, $booking] = $this->linkedCustomer('h4-private');
        $this->actingAs($owner);
        foreach (['/account/travel', '/account/bookings', '/account/bookings/'.$booking->reference_key] as $url) {
            $response = $this->get($url)->assertOk()
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
                ->assertDontSee('access_token_hash')
                ->assertDontSee('verification_token_hash')
                ->assertDontSee('contact_email_encrypted')
                ->assertDontSee('seat_allocation_id');

            // Symfony may reorder Cache-Control directives. Check the exact
            // set instead of the serialization order, without weakening the
            // private/no-store requirement.
            $cacheControl = (string) $response->baseResponse->headers->get('Cache-Control');
            $directives = array_map('trim', explode(',', $cacheControl));
            self::assertEqualsCanonicalizing(
                ['private', 'no-store', 'max-age=0'],
                $directives,
                'Private customer pages must disable shared/client caching.'
            );
        }
    }

    public function test_malformed_and_nonexistent_booking_references_return_404(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($owner)->get('/account/bookings/no')->assertNotFound();
        $this->get('/account/bookings/BOOK-not-found-1234')->assertNotFound();
    }

    public function test_new_account_routes_are_read_only_authenticated_and_verified(): void
    {
        foreach (['customer.travel.index', 'customer.bookings.index', 'customer.bookings.show'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route);
            self::assertSame(['GET', 'HEAD'], $route->methods());
            $middleware = implode('|', $route->gatherMiddleware());
            self::assertStringContainsString('auth', $middleware);
            self::assertStringContainsString('verified', $middleware);
        }
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->post('/account/bookings')->assertStatus(405);
    }

    public function test_source_stays_sidebar_free_and_never_exposes_guest_access_links(): void
    {
        foreach (['account/travel/index', 'account/bookings/index', 'account/bookings/show'] as $path) {
            $source = (string) file_get_contents(base_path('resources/js/pages/'.$path.'.tsx'));
            self::assertStringContainsString('<CustomerTravelLayout>', $source);
            self::assertStringNotContainsString('<AppSidebar', $source);
        }
        $layout = (string) file_get_contents(base_path('resources/js/layouts/customer-travel-layout.tsx'));
        self::assertStringContainsString('<PublicSiteLayout>', $layout);
        self::assertStringNotContainsString('BablysAdminLayout', $layout);
        self::assertStringNotContainsString('access_token', $layout);
        self::assertStringNotContainsString('request_reference=', $layout);
    }
}
