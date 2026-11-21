<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** P3H.5 source/route contract checks; no database writes. */
final class BookingReleaseSourceContractTest extends TestCase
{
    public function test_customer_history_routes_use_existing_login_and_verified_middleware(): void
    {
        foreach (['customer.travel.index', 'customer.bookings.index', 'customer.bookings.show'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, $name);
            self::assertSame(['GET', 'HEAD'], $route->methods());
            $middleware = implode('|', $route->gatherMiddleware());
            self::assertStringContainsString('auth', $middleware);
            self::assertStringContainsString('verified', $middleware);
        }
    }

    public function test_staff_mutations_are_post_only_and_need_both_tour_permissions(): void
    {
        foreach (['admin.bookings.confirm', 'admin.bookings.cancel', 'admin.booking-requests.convert'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, $name);
            self::assertSame(['POST'], $route->methods());
            $middleware = implode('|', $route->gatherMiddleware());
            self::assertStringContainsString('EnsureCmsStaff', $middleware);
            self::assertStringContainsString('tours.edit', $middleware);
            self::assertStringContainsString('tours.publish', $middleware);
        }
    }

    public function test_customer_booking_query_is_owner_scoped_not_email_matched(): void
    {
        $source = $this->source('app/Http/Controllers/Account/Travel/CustomerTravelAccountController.php');
        self::assertStringContainsString("->where('customer_id'", $source);
        self::assertStringContainsString("->whereNotNull('booking_id')", $source);
        self::assertStringContainsString("->whereIn('id',", $source);
        self::assertStringContainsString("->where('reference_key',", $source);
        self::assertStringNotContainsString("->where('email'", $source);
        self::assertStringNotContainsString('contact_email_encrypted', $source);
        self::assertStringNotContainsString('return $booking->toArray()', $source);
    }

    public function test_all_private_account_delivery_goes_through_noindex_no_store_response(): void
    {
        $source = $this->source('app/Http/Controllers/Account/Travel/CustomerTravelAccountController.php');
        self::assertStringContainsString('$response->headers->set(\'Cache-Control\', \'private, no-store, max-age=0\')', $source);
        self::assertStringContainsString('$response->headers->set(\'X-Robots-Tag\', \'noindex, nofollow\')', $source);
        self::assertStringContainsString('return $this->privatePage(', $source);
        self::assertStringNotContainsString("'price_lines_snapshot' =>", $source);
        self::assertStringNotContainsString("'seat_allocation_id' =>", $source);
    }

    public function test_public_seat_counts_use_authoritative_inventory_and_separate_booking_statuses(): void
    {
        $source = $this->source('app/Domain/Travel/Tours/PublicTourSeatAvailability.php');
        self::assertStringContainsString("'confirmed'", $source);
        self::assertStringContainsString("'pending_confirmation'", $source);
        self::assertStringContainsString('seats_committed', $source);
        self::assertStringContainsString("'available_seats'", $source);
        self::assertStringContainsString("'other_committed_seats'", $source);
        self::assertStringNotContainsString('contact_email_encrypted', $source);
        self::assertStringNotContainsString('access_token_hash', $source);
    }

    public function test_account_and_public_components_do_not_embed_admin_sidebar(): void
    {
        foreach ([
            'resources/js/pages/account/travel/index.tsx',
            'resources/js/pages/account/bookings/index.tsx',
            'resources/js/pages/account/bookings/show.tsx',
            'resources/js/pages/account/booking-requests/index.tsx',
            'resources/js/pages/public/tours/show.tsx',
            'resources/js/pages/public/booking-requests/create.tsx',
        ] as $file) {
            $source = $this->source($file);
            self::assertStringNotContainsString('<AppSidebar', $source, $file);
            self::assertStringNotContainsString('<BablysAdminLayout', $source, $file);
        }
        $layout = $this->source('resources/js/layouts/customer-travel-layout.tsx');
        self::assertStringContainsString('<PublicSiteLayout', $layout);
    }

    public function test_existing_quote_approval_and_admin_domain_adapters_are_not_bypassed(): void
    {
        $account = $this->source('resources/js/pages/account/booking-requests/index.tsx');
        self::assertStringContainsString('<QuoteApprovalPanel', $account);
        $admin = $this->source('app/Http/Controllers/Admin/Travel/AdminBookingActionsController.php');
        self::assertStringContainsString('convertVerified(', $admin);
        self::assertStringContainsString('$lifecycle->confirm(', $admin);
        self::assertStringContainsString('$lifecycle->cancel(', $admin);
        self::assertStringContainsString('Gate::forUser($actor)->authorize(\'tours.edit\')', $admin);
        self::assertStringContainsString('Gate::forUser($actor)->authorize(\'tours.publish\')', $admin);
        self::assertStringNotContainsString("->update(['status'", $admin);
    }

    private function source(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        self::assertIsString($contents, $path);
        return $contents;
    }
}
