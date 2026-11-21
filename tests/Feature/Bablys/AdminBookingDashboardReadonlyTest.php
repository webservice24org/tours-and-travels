<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingLifecycleManager;
use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Models\Travel\TourBooking;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Support\Bablys\TourBookingQaTestCase;

/**
 * P3H.1: senior-staff read-only booking management, privacy, snapshots and inventory.
 * The database guard runs before RefreshDatabase bootstraps the test application.
 */
final class AdminBookingDashboardReadonlyTest extends TourBookingQaTestCase
{
    protected function setUp(): void
    {
        $env = (string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? ''));
        $connection = (string) (getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? ''));
        $database = (string) (getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? ''));
        if ($env !== 'testing' || $connection !== 'mysql'
            || preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*_test\z/D', $database) !== 1
            || str_ends_with($database, 'race_test')) {
            throw new \RuntimeException('P3H.1 tests require a dedicated non-race MySQL *_test database and APP_ENV=testing.');
        }

        parent::setUp();
    }

    public function test_guest_redirects_and_customer_cannot_see_bookings(): void
    {
        $this->get('/admin/bookings')->assertRedirect(route('login'));
        $this->get('/admin/bookings/BOOK-private-0001')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->get('/admin/bookings')->assertForbidden();
        $this->get('/admin/bookings/BOOK-private-0001')->assertForbidden();
    }

    public function test_only_verified_active_staff_with_both_permissions_can_read(): void
    {
        $this->actingAs($this->staff('content_editor'))->get('/admin/bookings')->assertForbidden();
        $this->actingAs($this->staff('review_moderator'))->get('/admin/bookings')->assertForbidden();
        $admin = $this->staff('administrator');
        $this->actingAs($admin)->get('/admin/bookings')->assertOk();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->get('/admin/bookings')->assertForbidden();
        $admin->forceFill(['email_verified_at' => now(), 'cms_staff_disabled_at' => now()])->save();
        $this->get('/admin/bookings')->assertForbidden();
    }

    public function test_admin_receives_real_status_totals_and_seat_totals(): void
    {
        [$tour, $departure] = $this->readyTour('p3h-counts');
        $staff = $this->staff('administrator');
        $manager = app(TourBookingLifecycleManager::class);
        $pending = $manager->createInternal($staff, $tour, $departure, 'BOOK-p3h-count-01', 'standard-bdt', 1);
        $confirmed = $manager->createInternal($staff, $tour, $departure, 'BOOK-p3h-count-02', 'standard-bdt', 2);
        $manager->confirm($staff, $confirmed);
        $cancelled = $manager->createInternal($staff, $tour, $departure, 'BOOK-p3h-count-03', 'standard-bdt', 1);
        $manager->cancel($staff, $cancelled);

        $this->actingAs($staff)->get('/admin/bookings')->assertOk()->assertInertia(fn ($page) => $page
            ->component('admin/bookings/index')->where('read_only', true)
            ->where('metrics.total_bookings', 3)
            ->where('metrics.pending_bookings', 1)
            ->where('metrics.confirmed_bookings', 1)
            ->where('metrics.cancelled_bookings', 1)
            ->where('metrics.confirmed_seats', 2)
            ->where('metrics.pending_seats', 1)
            ->where('pagination.total', 3)
            ->has('bookings', 3)
            ->where('bookings.0.reference', (string) $cancelled->reference_key));
        self::assertSame(3, (int) $departure->fresh()->seats_committed);
        self::assertSame('pending_confirmation', $pending->fresh()?->status);
    }

    public function test_status_payment_and_literal_search_are_server_filtered(): void
    {
        [$tour, $departure] = $this->readyTour('p3h-filter');
        $staff = $this->staff('administrator');
        $manager = app(TourBookingLifecycleManager::class);
        $manager->createInternal($staff, $tour, $departure, 'BOOK-p3h-filter-A', 'standard-bdt', 1);
        $confirmed = $manager->createInternal($staff, $tour, $departure, 'BOOK-p3h-filter-B', 'standard-bdt', 1);
        $manager->confirm($staff, $confirmed);
        $this->actingAs($staff)->get('/admin/bookings?status=confirmed&q=filter-B')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('bookings', 1)->where('bookings.0.reference', 'BOOK-p3h-filter-B')
                ->where('pagination.total', 1));
        $this->get('/admin/bookings?payment_state=paid')->assertOk()
            ->assertInertia(fn ($page) => $page->has('bookings', 0));
        $this->get('/admin/bookings?q=%25')->assertOk()
            ->assertInertia(fn ($page) => $page->has('bookings', 0));
    }

    public function test_details_show_historical_price_and_fresh_inventory_without_writes(): void
    {
        [$tour, $departure] = $this->readyTour('p3h-detail');
        $staff = $this->staff('administrator');
        $booking = app(TourBookingLifecycleManager::class)->createInternal(
            $staff, $tour, $departure, 'BOOK-p3h-detail-01', 'standard-bdt', 2, 1
        );
        $oldTotal = (int) $booking->total_minor;
        $tour->update(['title' => 'Renamed Tour']);

        $this->actingAs($staff)->get('/admin/bookings/BOOK-p3h-detail-01')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/bookings/show')->where('read_only', true)
                ->where('booking.tour_title', 'Tour p3h-detail')
                ->where('booking.total_minor', $oldTotal)
                ->where('booking.seats', 3)
                ->where('booking.price_lines.0.traveler_type', 'adult')
                ->where('inventory.capacity_total', 10)
                ->where('inventory.seats_committed', 3)
                ->where('inventory.remaining', 7)
                ->where('request_contact', null));
        self::assertSame(3, (int) $departure->fresh()->seats_committed);
        self::assertSame('pending_confirmation', $booking->fresh()?->status);
    }

    public function test_linked_request_contacts_are_readable_only_by_senior_staff_and_tokens_never_leak(): void
    {
        [$tour, $departure] = $this->readyTour('p3h-linked');
        $staff = $this->staff('administrator');
        $booking = app(TourBookingLifecycleManager::class)->createInternal(
            $staff, $tour, $departure, 'BOOK-p3h-linked-1', 'standard-bdt', 1
        );
        $receipt = app(TourBookingRequestManager::class)->createGuest(
            $tour, $departure, 'standard-bdt', 1, 0, 'Private Guest', 'private-guest@example.com'
        );
        $requestRow = $receipt->request;
        $requestRow->booking_id = (int) $booking->getKey();
        $requestRow->status = 'converted';
        $requestRow->save();

        $this->actingAs($staff)->get('/admin/bookings/BOOK-p3h-linked-1')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('request_contact.name', 'Private Guest')
                ->where('request_contact.email', 'private-guest@example.com')
                ->missing('request_contact.verification_token_hash')
                ->missing('request_contact.access_token_hash')
                ->missing('request_contact.contact_email_encrypted'));
        $this->actingAs($this->staff('content_editor'))
            ->get('/admin/bookings/BOOK-p3h-linked-1')->assertForbidden();
        self::assertSame('converted', $requestRow->fresh()?->status);
    }

    public function test_invalid_or_unknown_reference_is_not_found(): void
    {
        $this->actingAs($this->staff('administrator'));
        $this->get('/admin/bookings/BOGUS')->assertNotFound();
        $this->get('/admin/bookings/BOOK-p3h-no-such')->assertNotFound();
    }

    public function test_endpoints_are_get_only_and_protect_caching(): void
    {
        [$tour, $departure] = $this->readyTour('p3h-readonly');
        $staff = $this->staff('administrator');
        app(TourBookingLifecycleManager::class)->createInternal(
            $staff, $tour, $departure, 'BOOK-p3h-readonly-1', 'standard-bdt', 1
        );
        $this->actingAs($staff)->get('/admin/bookings')
            ->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/admin/bookings/BOOK-p3h-readonly-1')
            ->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->post('/admin/bookings')->assertStatus(405);
        $this->post('/admin/bookings/BOOK-p3h-readonly-1')->assertStatus(405);
        self::assertSame(1, (int) $departure->fresh()->seats_committed);
    }

    public function test_admin_routes_have_both_permissions(): void
    {
        foreach (['admin.bookings.index', 'admin.bookings.show'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route);
            self::assertSame(['GET', 'HEAD'], $route->methods());
            $middleware = implode('|', $route->gatherMiddleware());
            self::assertStringContainsString('EnsureCmsStaff', $middleware);
            self::assertStringContainsString('tours.edit', $middleware);
            self::assertStringContainsString('tours.publish', $middleware);
        }
    }

    public function test_react_admin_pages_use_existing_layout_without_duplicate_sidebar(): void
    {
        foreach (['index', 'show'] as $page) {
            $source = (string) file_get_contents(base_path('resources/js/pages/admin/bookings/'.$page.'.tsx'));
            self::assertStringContainsString('<BablysAdminLayout>', $source);
            self::assertStringNotContainsString('<AppSidebar', $source);
            self::assertStringNotContainsString('<aside', $source);
            self::assertStringNotContainsString('window.confirm(', $source);
        }
    }
}
