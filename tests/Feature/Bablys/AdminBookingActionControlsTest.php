<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingLifecycleManager;
use App\Domain\Travel\Bookings\TourBookingQuoteAcceptanceManager;
use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Models\Travel\Tour;
use App\Models\Travel\TourBooking;
use App\Models\Travel\TourDeparture;
use App\Models\Travel\TourPriceVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Bablys\TourBookingQaTestCase;

/** P3H.2 HTTP integration: admin mutation adapter must never bypass booking-domain invariants. */
final class AdminBookingActionControlsTest extends TourBookingQaTestCase
{
    protected function setUp(): void
    {
        $env = (string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? ''));
        $connection = (string) (getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? ''));
        $database = (string) (getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? ''));
        if ($env !== 'testing' || $connection !== 'mysql'
            || preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*_test\z/D', $database) !== 1
            || str_ends_with($database, 'race_test')) {
            throw new \RuntimeException('P3H.2 requires dedicated, non-race MySQL *_test and APP_ENV=testing.');
        }
        parent::setUp();
    }

    /** @return array{0: TourBooking, 1: TourDeparture, 2: User, 3: Tour} */
    private function createPending(string $suffix, int $adults = 2): array
    {
        [$tour, $departure] = $this->readyTour('h2-'.$suffix);
        $staff = $this->staff('administrator');
        $booking = app(TourBookingLifecycleManager::class)->createInternal(
            $staff, $tour, $departure, 'BOOK-p3h2-'.$suffix, 'standard-bdt', $adults
        );
        return [$booking, $departure, $staff, $tour];
    }

    public function test_guests_and_customers_cannot_mutate_booking_records(): void
    {
        [$booking, $departure] = $this->createPending('auth');
        $confirm = '/admin/bookings/'.$booking->reference_key.'/confirm';
        $cancel = '/admin/bookings/'.$booking->reference_key.'/cancel';
        $this->post($confirm)->assertRedirect(route('login'));
        $this->post($cancel)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))->post($confirm)->assertForbidden();
        $this->post($cancel)->assertForbidden();
        self::assertSame('pending_confirmation', $booking->fresh()?->status);
        self::assertSame(2, (int) $departure->fresh()->seats_committed);
    }

    public function test_unprivileged_disabled_and_unverified_staff_cannot_confirm(): void
    {
        [$booking] = $this->createPending('staff-auth');
        $endpoint = '/admin/bookings/'.$booking->reference_key.'/confirm';
        $this->actingAs($this->staff('content_editor'))->post($endpoint)->assertForbidden();
        $staff = $this->staff('administrator');
        $staff->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($staff)->post($endpoint)->assertForbidden();
        $staff->forceFill(['email_verified_at' => now(), 'cms_staff_disabled_at' => now()])->save();
        $this->post($endpoint)->assertForbidden();
        self::assertSame('pending_confirmation', $booking->fresh()?->status);
    }

    public function test_admin_mutation_routes_are_post_only_with_both_permissions(): void
    {
        foreach (['admin.bookings.confirm', 'admin.bookings.cancel', 'admin.booking-requests.convert'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route);
            self::assertSame(['POST'], $route->methods());
            $middleware = implode('|', $route->gatherMiddleware());
            self::assertStringContainsString('EnsureCmsStaff', $middleware);
            self::assertStringContainsString('tours.edit', $middleware);
            self::assertStringContainsString('tours.publish', $middleware);
        }
        $this->actingAs($this->staff('administrator'));
        $this->get('/admin/bookings/BOOK-p3h2-missing/confirm')->assertStatus(405);
        $this->get('/admin/bookings/BOOK-p3h2-missing/cancel')->assertStatus(405);
        $this->get('/admin/booking-requests/BRQ-'.str_repeat('0', 32).'/convert')->assertStatus(405);
    }

    public function test_confirm_via_http_does_not_allocate_seats_again_or_take_payment(): void
    {
        [$booking, $departure, $staff] = $this->createPending('confirm', 3);
        $this->actingAs($staff)->post('/admin/bookings/'.$booking->reference_key.'/confirm', [
            'total_minor' => 1, 'payment_state' => 'paid', 'seats' => 100,
        ])->assertRedirect(route('admin.bookings.show', ['reference' => $booking->reference_key]));
        self::assertSame('confirmed', $booking->fresh()?->status);
        self::assertSame('not_collected', $booking->fresh()?->payment_state);
        self::assertSame(3, (int) $departure->fresh()->seats_committed);
        self::assertSame(3, (int) $booking->fresh()?->seats);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 1);
    }

    public function test_repeated_confirmation_is_idempotent_and_audited_once(): void
    {
        [$booking, $departure, $staff] = $this->createPending('confirm-repeat');
        $endpoint = '/admin/bookings/'.$booking->reference_key.'/confirm';
        $this->actingAs($staff)->post($endpoint)->assertRedirect();
        $this->post($endpoint)->assertRedirect();
        self::assertSame('confirmed', $booking->fresh()?->status);
        self::assertSame(2, (int) $departure->fresh()->seats_committed);
        self::assertSame(1, DB::table('cms_audit_events')->where('action', 'tour.booking.confirmed')->count());
    }

    public function test_cancel_pending_releases_seats_once(): void
    {
        [$booking, $departure, $staff] = $this->createPending('cancel-pending');
        $endpoint = '/admin/bookings/'.$booking->reference_key.'/cancel';
        $this->actingAs($staff)->post($endpoint)->assertRedirect();
        $this->post($endpoint)->assertRedirect();
        self::assertSame('cancelled', $booking->fresh()?->status);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseHas('travel_tour_seat_allocations', ['reference_key' => $booking->reference_key, 'status' => 'released']);
        self::assertSame(1, DB::table('cms_audit_events')->where('action', 'tour.booking.cancelled')->count());
    }

    public function test_cancel_confirmed_releases_seats_and_never_refunds(): void
    {
        [$booking, $departure, $staff] = $this->createPending('cancel-confirmed');
        $this->actingAs($staff)->post('/admin/bookings/'.$booking->reference_key.'/confirm')->assertRedirect();
        $this->post('/admin/bookings/'.$booking->reference_key.'/cancel')->assertRedirect();
        self::assertSame('cancelled', $booking->fresh()?->status);
        self::assertSame('not_collected', $booking->fresh()?->payment_state);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
    }

    public function test_paid_booking_cannot_be_cancelled_or_release_seats(): void
    {
        [$booking, $departure, $staff] = $this->createPending('paid');
        DB::table('travel_tour_bookings')->where('id', $booking->getKey())->update(['payment_state' => 'paid']);
        $this->actingAs($staff)->post('/admin/bookings/'.$booking->reference_key.'/cancel')->assertSessionHasErrors();
        self::assertSame('pending_confirmation', $booking->fresh()?->status);
        self::assertSame(2, (int) $departure->fresh()->seats_committed);
    }

    public function test_cancelled_booking_cannot_be_reconfirmed(): void
    {
        [$booking, $departure, $staff] = $this->createPending('cancelled');
        $this->actingAs($staff)->post('/admin/bookings/'.$booking->reference_key.'/cancel')->assertRedirect();
        $this->post('/admin/bookings/'.$booking->reference_key.'/confirm')->assertSessionHasErrors();
        self::assertSame('cancelled', $booking->fresh()?->status);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
    }

    public function test_verified_but_unapproved_request_cannot_convert_or_reserve(): void
    {
        [$tour, $departure] = $this->readyTour('h2-convert-unapproved');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $requestRow = app(TourBookingRequestManager::class)->createCustomer(
            $owner, $tour, $departure, 'standard-bdt', 1, 0
        )->request;
        $staff = $this->staff('administrator');
        $this->actingAs($staff)->post('/admin/booking-requests/'.$requestRow->reference_key.'/convert')
            ->assertSessionHasErrors();
        self::assertSame('verified', $requestRow->fresh()?->status);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_approved_customer_request_converts_once_and_redirects_to_booking(): void
    {
        [$tour, $departure] = $this->readyTour('h2-convert-approved');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $requestRow = app(TourBookingRequestManager::class)->createCustomer(
            $owner, $tour, $departure, 'standard-bdt', 2, 0
        )->request;
        $quotes = app(TourBookingQuoteAcceptanceManager::class);
        $preview = $quotes->previewCustomer($owner, $requestRow);
        $quotes->acceptCustomer($owner, $requestRow, (int) $preview['revision'], (string) $preview['fingerprint']);
        $staff = $this->staff('administrator');
        $endpoint = '/admin/booking-requests/'.$requestRow->reference_key.'/convert';
        $first = $this->actingAs($staff)->post($endpoint)->assertRedirect();
        $booking = TourBooking::query()->firstOrFail();
        $first->assertRedirect(route('admin.bookings.show', ['reference' => $booking->reference_key]));
        $this->post($endpoint)->assertRedirect(route('admin.bookings.show', ['reference' => $booking->reference_key]));
        self::assertSame('pending_confirmation', $booking->fresh()?->status);
        self::assertSame('not_collected', $booking->fresh()?->payment_state);
        self::assertSame('converted', $requestRow->fresh()?->status);
        self::assertSame(2, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_bookings', 1);
        self::assertSame(1, DB::table('cms_audit_events')->where('action', 'tour.booking.created')->count());
    }

    public function test_changed_quote_requires_new_customer_acceptance(): void
    {
        [$tour, $departure] = $this->readyTour('h2-repricing');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $requestRow = app(TourBookingRequestManager::class)->createCustomer(
            $owner, $tour, $departure, 'standard-bdt', 1, 0
        )->request;
        $quotes = app(TourBookingQuoteAcceptanceManager::class);
        $preview = $quotes->previewCustomer($owner, $requestRow);
        $quotes->acceptCustomer($owner, $requestRow, (int) $preview['revision'], (string) $preview['fingerprint']);
        $variant = TourPriceVariant::query()->where('tour_id', $tour->getKey())->where('code', 'standard-bdt')->firstOrFail();
        $variant->rates()->where('traveler_type', 'adult')->update(['regular_minor' => 1999000]);
        $staff = $this->staff('administrator');
        $this->actingAs($staff)->post('/admin/booking-requests/'.$requestRow->reference_key.'/convert')
            ->assertSessionHasErrors();
        self::assertSame('verified', $requestRow->fresh()?->status);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_guest_request_must_be_verified_and_approved_before_conversion(): void
    {
        [$tour, $departure] = $this->readyTour('h2-guest');
        $guest = app(TourBookingRequestManager::class)->createGuest(
            $tour, $departure, 'standard-bdt', 1, 0, 'Secret Visitor', 'secretvisitor@example.com'
        );
        $staff = $this->staff('administrator');
        $endpoint = '/admin/booking-requests/'.$guest->request->reference_key.'/convert';
        $this->actingAs($staff)->post($endpoint)->assertSessionHasErrors();
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_invalid_references_and_unprivileged_conversion_are_blocked(): void
    {
        [$tour, $departure] = $this->readyTour('h2-convert-auth');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $requestRow = app(TourBookingRequestManager::class)->createCustomer(
            $owner, $tour, $departure, 'standard-bdt', 1, 0
        )->request;
        $endpoint = '/admin/booking-requests/'.$requestRow->reference_key.'/convert';
        $this->actingAs($owner)->post($endpoint)->assertForbidden();
        $this->actingAs($this->staff('content_editor'))->post($endpoint)->assertForbidden();
        $this->actingAs($this->staff('administrator'))->post('/admin/booking-requests/not-a-ref/convert')->assertNotFound();
        $this->post('/admin/bookings/unknown/confirm')->assertNotFound();
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
    }

    public function test_admin_action_pages_use_single_existing_sidebar_and_explicit_confirmation(): void
    {
        $show = (string) file_get_contents(base_path('resources/js/pages/admin/bookings/show.tsx'));
        $requestList = (string) file_get_contents(base_path('resources/js/pages/admin/booking-requests/index.tsx'));
        foreach ([$show, $requestList] as $source) {
            self::assertStringContainsString('<BablysAdminLayout>', $source);
            self::assertStringNotContainsString('<AppSidebar', $source);
            self::assertStringNotContainsString('window.confirm(', $source);
        }
        self::assertStringContainsString('Type the complete booking reference', $show);
        self::assertStringContainsString('approved', $requestList);
    }
}
