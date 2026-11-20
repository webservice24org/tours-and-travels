<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingQuoteAcceptanceManager;
use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Models\Travel\TourBookingRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bablys\TourBookingQaTestCase;

/** P3E.6A: behavioral authorization/privacy checks, not a substitute for browser CSRF testing. */
final class BookingFinalSecurityRegressionTest extends TourBookingQaTestCase
{
    public function test_guest_access_requires_both_email_verification_and_correct_access_secret(): void
    {
        [$tour, $departure] = $this->readyTour('p3e6a-guest-proof');
        $receipt = app(TourBookingRequestManager::class)->createGuest(
            $tour,
            $departure,
            'standard-bdt',
            1,
            0,
            'Proof Traveler',
            'proof@example.com'
        );
        $reference = (string) $receipt->request->reference_key;
        $access = (string) $receipt->accessToken;

        $this->postJson('/booking-requests/guest/status', [
            'reference' => $reference,
            'access_token' => $access,
        ])->assertNotFound();
        app(TourBookingRequestManager::class)->verifyGuest(
            $receipt->request,
            (string) $receipt->verificationToken
        );
        $this->postJson('/booking-requests/guest/status', [
            'reference' => $reference,
            'access_token' => str_repeat('f', 64),
        ])->assertNotFound();
        $this->postJson('/booking-requests/guest/status', [
            'reference' => $reference,
            'access_token' => $access,
        ])->assertOk()->assertJsonPath('data.status', 'verified')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertDontSee($access)
            ->assertDontSee('proof@example.com')
            ->assertDontSee('contact_email_encrypted');
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_customer_cannot_review_or_approve_another_customer_quote(): void
    {
        [$tour, $departure] = $this->readyTour('p3e6a-quote-owner');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $intruder = User::factory()->create(['email_verified_at' => now()]);
        $row = app(TourBookingRequestManager::class)->createCustomer(
            $owner,
            $tour,
            $departure,
            'standard-bdt',
            1,
            0
        )->request;
        $base = '/account/booking-requests/' . $row->reference_key;

        $this->actingAs($intruder)->postJson($base . '/quote-review')->assertNotFound();
        $this->postJson($base . '/quote-accept', [
            'revision' => 1,
            'fingerprint' => str_repeat('a', 64),
        ])->assertNotFound();
        $this->assertDatabaseCount('travel_booking_request_quote_versions', 0);
        $this->actingAs($owner)->postJson($base . '/quote-review')
            ->assertOk()->assertJsonPath('quote.nonbinding', true)
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_client_supplied_price_never_overrides_server_quote(): void
    {
        [$tour, $departure] = $this->readyTour('p3e6a-authority');
        $this->postJson('/booking-requests/quote', [
            'tour_id' => $tour->id,
            'departure_id' => $departure->id,
            'variant_code' => 'standard-bdt',
            'adults' => 2,
            'children' => 1,
            'total_minor' => 1,
            'regular_total_minor' => 1,
            'currency' => 'USD',
            'payment_state' => 'paid',
        ])->assertOk()->assertJsonPath('quote.total_minor', 3800000)
            ->assertJsonPath('quote.currency', 'BDT')
            ->assertJsonPath('inventory_reserved', false);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_forged_fingerprint_does_not_create_approval_or_reserve_seats(): void
    {
        [$tour, $departure] = $this->readyTour('p3e6a-fingerprint');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $row = app(TourBookingRequestManager::class)->createCustomer(
            $owner,
            $tour,
            $departure,
            'standard-bdt',
            1,
            0
        )->request;
        $uri = '/account/booking-requests/' . $row->reference_key;
        $this->actingAs($owner)->postJson($uri . '/quote-review')->assertOk();
        $this->postJson($uri . '/quote-accept', [
            'revision' => 1,
            'fingerprint' => str_repeat('f', 64),
            'total_minor' => 1,
        ])->assertUnprocessable();
        self::assertNull(DB::table('travel_booking_request_quote_versions')->value('accepted_at_utc'));
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_booking_request_requires_consent_before_writing_any_request(): void
    {
        [$tour, $departure] = $this->readyTour('p3e6a-consent');
        $this->post('/booking-requests', [
            'tour_id' => (int) $tour->id,
            'departure_id' => (int) $departure->id,
            'variant_code' => 'standard-bdt',
            'adults' => 1,
            'children' => 0,
            'contact_name' => 'Consent Traveler',
            'contact_email' => 'consent@example.com',
            'submission_id' => 'd9aecb93-1976-49c8-8bc4-ccbadb106311',
            'consent' => false,
        ])->assertSessionHasErrors('consent');
        $this->assertDatabaseCount('travel_tour_booking_requests', 0);
    }

    public function test_customer_account_index_never_shows_other_customer_requests(): void
    {
        [$tour, $departure] = $this->readyTour('p3e6a-account');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $intruder = User::factory()->create(['email_verified_at' => now()]);
        $row = app(TourBookingRequestManager::class)->createCustomer(
            $owner,
            $tour,
            $departure,
            'standard-bdt',
            1,
            0
        )->request;
        $this->actingAs($intruder)->get('/account/booking-requests')
            ->assertOk()->assertInertia(fn($page) => $page->has('requests', 0));
        $this->post('/account/booking-requests/' . $row->reference_key . '/withdraw')->assertNotFound();
        self::assertSame('verified', $row->fresh()?->status);
        $this->actingAs($owner)->get('/account/booking-requests')
            ->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertInertia(fn($page) => $page->has('requests', 1));
    }

    public function test_guest_verification_get_never_activates_request(): void
    {
        [$tour, $departure] = $this->readyTour('p3e6a-get-safe');
        $row = app(TourBookingRequestManager::class)->createGuest(
            $tour,
            $departure,
            'standard-bdt',
            1,
            0,
            'No Get Traveler',
            'noget@example.com'
        )->request;
        $this->get('/booking-requests/verify')->assertOk();
        $this->get('/booking-requests/manage')->assertOk();
        self::assertSame('awaiting_verification', $row->fresh()?->status);
    }
}
