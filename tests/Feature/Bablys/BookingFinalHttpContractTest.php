<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Mail\GuestBookingRequestLinksMail;
use App\Models\Travel\TourBookingRequest;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\Bablys\TourBookingQaTestCase;

/** P3E.6C: HTTP, route, cache and privacy contracts. Visual a11y still needs browser review. */
final class BookingFinalHttpContractTest extends TourBookingQaTestCase
{
    public function test_public_booking_page_is_nonbinding_and_inertia_component_is_present(): void
    {
        [$tour, $departure] = $this->readyTour('p3e6c-create');
        $this->get('/tours/' . $tour->slug . '/booking-request')->assertOk()
            ->assertInertia(fn($page) => $page
                ->component('public/booking-requests/create')
                ->where('nonbinding', true)
                ->where('tour.id', (int) $tour->id)
                ->has('departures', 1));
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
    }

    public function test_guest_submission_mail_has_fragment_only_secrets_and_no_browser_leak(): void
    {
        Mail::fake();
        [$tour, $departure] = $this->readyTour('p3e6c-email');
        $this->post('/booking-requests', [
            'tour_id' => (int) $tour->id,
            'departure_id' => (int) $departure->id,
            'variant_code' => 'standard-bdt',
            'adults' => 1,
            'children' => 0,
            'contact_name' => 'Mail Guest',
            'contact_email' => 'mailguest@example.com',
            'consent' => true,
            'submission_id' => (string) Str::uuid(),
        ])->assertRedirect('/booking-requests/submitted');
        $mail = null;
        Mail::assertSent(GuestBookingRequestLinksMail::class, function (GuestBookingRequestLinksMail $sent) use (&$mail): bool {
            $mail = $sent;
            return true;
        });
        Mail::assertSent(GuestBookingRequestLinksMail::class, 1);
        self::assertInstanceOf(GuestBookingRequestLinksMail::class, $mail);
        self::assertNotFalse(strpos($mail->verifyUrl, '#reference='));
        self::assertNotFalse(strpos($mail->manageUrl, '#reference='));
        self::assertNull(parse_url($mail->verifyUrl, PHP_URL_QUERY));
        self::assertNull(parse_url($mail->manageUrl, PHP_URL_QUERY));
        $verifySecret = (string) parse_url($mail->verifyUrl, PHP_URL_FRAGMENT);
        $manageSecret = (string) parse_url($mail->manageUrl, PHP_URL_FRAGMENT);
        $this->get('/booking-requests/submitted')->assertOk()
            ->assertDontSee($verifySecret)->assertDontSee($manageSecret)
            ->assertDontSee('token=')->assertDontSee('access=');
        $row = TourBookingRequest::query()->firstOrFail();
        self::assertSame('awaiting_verification', $row->status);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_guest_quote_post_endpoints_are_not_available_as_mutating_get_requests(): void
    {
        foreach ([
            'booking-requests.guest.quote-review',
            'booking-requests.guest.quote-accept',
            'booking-requests.customer.quote-review',
            'booking-requests.customer.quote-accept',
            'booking-requests.verify',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, $name . ' route is not registered');
            self::assertContains('POST', $route->methods(), $name . ' must use POST');
            self::assertNotContains('GET', $route->methods(), $name . ' must not use GET');
        }
        $this->get('/booking-requests/guest/quote-accept')->assertStatus(405);
        $this->get('/booking-requests/verify')->assertOk();
    }

    public function test_staff_booking_request_review_requires_authorization_and_is_read_only(): void
    {
        [$tour, $departure] = $this->readyTour('p3e6c-staff');
        app(TourBookingRequestManager::class)->createGuest(
            $tour,
            $departure,
            'standard-bdt',
            1,
            0,
            'Restricted Guest',
            'staffpii@example.com'
        );
        $this->get('/admin/booking-requests')->assertRedirect();
        $this->actingAs($this->staff('administrator'))
            ->get('/admin/booking-requests')->assertOk()
            ->assertInertia(fn($page) => $page->component('admin/booking-requests/index')
                ->where('review_only', true)->has('requests', 1));
        $this->post('/admin/booking-requests')->assertStatus(405);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_customer_page_and_quote_response_have_private_no_store_cache(): void
    {
        [$tour, $departure] = $this->readyTour('p3e6c-cache');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $request = app(TourBookingRequestManager::class)->createCustomer(
            $owner,
            $tour,
            $departure,
            'standard-bdt',
            1,
            0
        )->request;
        $this->actingAs($owner)->get('/account/booking-requests')
            ->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->postJson('/account/booking-requests/' . $request->reference_key . '/quote-review')
            ->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_email_unverified_customer_cannot_submit_customer_booking_request(): void
    {
        [$tour, $departure] = $this->readyTour('p3e6c-unverified');
        $owner = User::factory()->create(['email_verified_at' => null]);
        $this->actingAs($owner)->post('/booking-requests', [
            'tour_id' => (int) $tour->id,
            'departure_id' => (int) $departure->id,
            'variant_code' => 'standard-bdt',
            'adults' => 1,
            'children' => 0,
            'consent' => true,
            'submission_id' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->assertDatabaseCount('travel_tour_booking_requests', 0);
    }

    public function test_booking_expiry_and_overdue_commands_offer_safe_dry_runs(): void
    {
        $this->artisan('bablys:bookings:expire-requests', ['--limit' => '10', '--dry-run' => true])
            ->assertExitCode(0);
        $this->artisan('bablys:bookings:flag-overdue', ['--limit' => '10', '--dry-run' => true])
            ->assertExitCode(0);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }
}
