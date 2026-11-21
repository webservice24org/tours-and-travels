<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Models\User;
use Tests\Support\Bablys\TourBookingQaTestCase;

/** P3F.4A: public website enquiry bridge, privacy invariants and source layout contract. */
final class PublicBookingEnquiryJourneyTest extends TourBookingQaTestCase
{
    public function test_all_booking_enquiry_pages_explicitly_use_the_sidebar_free_public_shell(): void
    {
        foreach ([
            'public/booking-requests/create.tsx' => 'CreateBookingRequest',
            'public/booking-requests/submitted.tsx' => 'BookingRequestSubmitted',
            'public/booking-requests/verify.tsx' => 'VerifyBookingRequest',
            'public/booking-requests/manage.tsx' => 'GuestBookingRequestManage',
        ] as $page => $component) {
            $source = file_get_contents(base_path('resources/js/pages/'.$page));
            self::assertIsString($source, $page);
            self::assertStringContainsString('PublicSiteLayout', $source, $page);
            self::assertStringContainsString($component.'.layout = ', $source, $page);
            self::assertStringNotContainsString('AppSidebar', $source, $page);
            self::assertStringNotContainsString('AppLayout', $source, $page);
        }

        // P3H.4: the customer request screen uses an account shell which
        // wraps the same sidebar-free public site layout. Do not require a
        // direct PublicSiteLayout import on this one account page.
        $page = 'account/booking-requests/index.tsx';
        $source = file_get_contents(base_path('resources/js/pages/'.$page));
        self::assertIsString($source, $page);
        self::assertStringContainsString('CustomerTravelLayout', $source, $page);
        self::assertStringContainsString('<CustomerTravelLayout>', $source, $page);
        self::assertStringContainsString('<QuoteApprovalPanel', $source, $page);
        self::assertStringNotContainsString('AppSidebar', $source, $page);
        self::assertStringNotContainsString('AppLayout', $source, $page);

        $shell = file_get_contents(base_path('resources/js/layouts/customer-travel-layout.tsx'));
        self::assertIsString($shell);
        self::assertStringContainsString('PublicSiteLayout', $shell);
        self::assertStringContainsString('<PublicSiteLayout>', $shell);
        self::assertStringNotContainsString('AppSidebar', $shell);
        self::assertStringNotContainsString('AppLayout', $shell);
    }

    public function test_published_tour_detail_connects_to_its_nonbinding_enquiry_form(): void
    {
        [$tour, $departure] = $this->readyTour('p3f4a-detail');
        $url = route('booking-requests.create', ['tour' => $tour->slug]);
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->component('public/tours/show')->where('tour.enquiry_url', $url));
        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
            ->component('public/booking-requests/create')
            ->where('nonbinding', true)
            ->where('tour.slug', $tour->slug)
            ->where('tour.id', (int) $tour->getKey())
            ->has('departures', 1)->has('variants', 1)
            ->missing('booking')->missing('payment_state'));
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_booking_requests', 0);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_refreshing_enquiry_and_public_details_never_reserves_seats(): void
    {
        [$tour, $departure] = $this->readyTour('p3f4a-safe-get');
        for ($i = 0; $i < 3; $i++) {
            $this->get('/tours/'.$tour->slug)->assertOk();
            $this->get('/tours/'.$tour->slug.'/booking-request')->assertOk();
        }
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_booking_requests', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_unpublished_tour_has_no_public_enquiry_page(): void
    {
        [$tour] = $this->readyTour('p3f4a-hidden');
        $tour->update(['status' => 'draft', 'published_at' => null]);
        $this->get('/tours/'.$tour->slug)->assertNotFound();
        $this->get('/tours/'.$tour->slug.'/booking-request')->assertNotFound();
        $this->assertDatabaseCount('travel_tour_booking_requests', 0);
    }

    public function test_price_preview_from_enquiry_is_nonbinding_and_keeps_server_authority(): void
    {
        [$tour, $departure] = $this->readyTour('p3f4a-quote');
        $this->postJson('/booking-requests/quote', [
            'tour_id' => (int) $tour->getKey(), 'departure_id' => (int) $departure->getKey(),
            'variant_code' => 'standard-bdt', 'adults' => 1, 'children' => 0,
            'total_minor' => 1, 'currency' => 'USD', 'payment_state' => 'paid',
        ])->assertOk()->assertJsonPath('nonbinding', true)
            ->assertJsonPath('inventory_reserved', false)
            ->assertJsonPath('quote.currency', 'BDT')
            ->assertJsonMissing(['payment_state' => 'paid']);
        $this->assertDatabaseCount('travel_tour_booking_requests', 0);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
    }

    public function test_guest_email_proof_and_manage_pages_do_not_mutate_requests_on_get(): void
    {
        [$tour, $departure] = $this->readyTour('p3f4a-guest');
        $row = app(TourBookingRequestManager::class)->createGuest(
            $tour, $departure, 'standard-bdt', 1, 0, 'Guest Visitor', 'visitor@example.test'
        )->request;
        $this->get('/booking-requests/verify')->assertOk()
            ->assertInertia(fn ($page) => $page->component('public/booking-requests/verify'));
        $this->get('/booking-requests/manage')->assertOk()
            ->assertInertia(fn ($page) => $page->component('public/booking-requests/manage'));
        $this->get('/booking-requests/submitted')->assertOk()
            ->assertInertia(fn ($page) => $page->component('public/booking-requests/submitted'));
        self::assertSame('awaiting_verification', $row->fresh()?->status);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_guest_status_requires_the_private_access_secret(): void
    {
        [$tour, $departure] = $this->readyTour('p3f4a-token');
        $receipt = app(TourBookingRequestManager::class)->createGuest(
            $tour, $departure, 'standard-bdt', 1, 0, 'Token Visitor', 'token@example.test'
        );
        app(TourBookingRequestManager::class)->verifyGuest(
            $receipt->request, (string) $receipt->verificationToken
        );
        $this->postJson('/booking-requests/guest/status', [
            'reference' => (string) $receipt->request->reference_key,
            'access_token' => str_repeat('f', 64),
        ])->assertNotFound();
        $this->postJson('/booking-requests/guest/status', [
            'reference' => (string) $receipt->request->reference_key,
            'access_token' => (string) $receipt->accessToken,
        ])->assertOk()->assertJsonPath('data.status', 'verified');
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_customer_request_history_remains_owner_scoped_and_nonbinding(): void
    {
        [$tour, $departure] = $this->readyTour('p3f4a-owner');
        $owner = User::factory()->create(['email_verified_at' => now('UTC')]);
        $other = User::factory()->create(['email_verified_at' => now('UTC')]);
        $row = app(TourBookingRequestManager::class)->createCustomer(
            $owner, $tour, $departure, 'standard-bdt', 1, 0
        )->request;
        $this->actingAs($other)->get('/account/booking-requests')->assertOk()
            ->assertInertia(fn ($page) => $page->component('account/booking-requests/index')->has('requests', 0));
        $this->actingAs($owner)->get('/account/booking-requests')->assertOk()
            ->assertInertia(fn ($page) => $page->component('account/booking-requests/index')
                ->where('nonbinding', true)->has('requests', 1));
        self::assertSame('verified', $row->fresh()?->status);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }
}
