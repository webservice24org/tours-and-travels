<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingQuoteAcceptanceManager;
use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Models\Travel\TourBookingRequest;
use App\Models\Travel\TourPriceVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Bablys\TourBookingQaTestCase;

final class TourBookingQuoteAcceptanceTest extends TourBookingQaTestCase
{
    private function quotes(): TourBookingQuoteAcceptanceManager
    {
        return app(TourBookingQuoteAcceptanceManager::class);
    }

    private function requests(): TourBookingRequestManager
    {
        return app(TourBookingRequestManager::class);
    }

    /** @return array<string,int|string|bool> */
    private function approve(User $owner, TourBookingRequest $row): array
    {
        $price = $this->quotes()->previewCustomer($owner, $row);
        return $this->quotes()->acceptCustomer($owner, $row,
            (int) $price['revision'], (string) $price['fingerprint']);
    }

    public function test_customer_approval_is_required_before_staff_can_commit_seats(): void
    {
        [$tour, $departure] = $this->readyTour('quote-approval-required');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $row = $this->requests()->createCustomer($owner, $tour, $departure, 'standard-bdt', 2, 1)->request;
        try {
            $this->requests()->convertVerified($this->staff('administrator'), $row);
            self::fail('Conversion without quote approval was incorrectly allowed.');
        } catch (ValidationException) {
            self::assertSame(0, (int) $departure->fresh()->seats_committed);
            $this->assertDatabaseCount('travel_tour_bookings', 0);
        }
        $approved = $this->approve($owner, $row);
        self::assertSame('3800000', $approved['total_minor']);
        self::assertTrue($approved['accepted']);
        self::assertFalse($approved['inventory_reserved']);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $booking = $this->requests()->convertVerified($this->staff('administrator'), $row);
        self::assertSame(3800000, (int) $booking->total_minor);
        self::assertSame('not_collected', $booking->payment_state);
        self::assertSame(3, (int) $departure->fresh()->seats_committed);
        self::assertSame($booking->id, $this->requests()->convertVerified($this->staff('administrator'), $row)->id);
    }

    public function test_changed_price_blocks_conversion_until_explicit_reapproval(): void
    {
        [$tour, $departure] = $this->readyTour('quote-reprice');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $row = $this->requests()->createCustomer($owner, $tour, $departure, 'standard-bdt', 1, 0)->request;
        $original = $this->approve($owner, $row);
        $variant = TourPriceVariant::query()->where('tour_id', $tour->getKey())->firstOrFail();
        $variant->rates()->where('traveler_type', 'adult')->update(['regular_minor' => 2500000]);
        try {
            $this->requests()->convertVerified($this->staff('administrator'), $row);
            self::fail('Changed price must reject previously approved quote.');
        } catch (ValidationException) {
            self::assertSame(0, (int) $departure->fresh()->seats_committed);
            $this->assertDatabaseCount('travel_tour_bookings', 0);
            self::assertSame('verified', $row->fresh()?->status);
        }
        $revised = $this->quotes()->previewCustomer($owner, $row);
        self::assertSame(2, $revised['revision']);
        self::assertSame('2500000', $revised['total_minor']);
        self::assertFalse($revised['accepted']);
        self::assertNotSame($original['fingerprint'], $revised['fingerprint']);
        $this->quotes()->acceptCustomer($owner, $row, (int) $revised['revision'], (string) $revised['fingerprint']);
        $booking = $this->requests()->convertVerified($this->staff('administrator'), $row);
        self::assertSame(2500000, (int) $booking->total_minor);
        $this->assertDatabaseCount('travel_booking_request_quote_versions', 2);
    }

    public function test_old_revision_or_forged_hash_cannot_be_accepted(): void
    {
        [$tour, $departure] = $this->readyTour('quote-forged');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $row = $this->requests()->createCustomer($owner, $tour, $departure, 'standard-bdt', 1, 0)->request;
        $preview = $this->quotes()->previewCustomer($owner, $row);
        $this->expectException(ValidationException::class);
        $this->quotes()->acceptCustomer($owner, $row, (int) $preview['revision'], str_repeat('0', 64));
    }

    public function test_no_duplicate_revision_or_acceptance_on_retries(): void
    {
        [$tour, $departure] = $this->readyTour('quote-repeat');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $row = $this->requests()->createCustomer($owner, $tour, $departure, 'standard-bdt', 1, 0)->request;
        $this->approve($owner, $row);
        $this->approve($owner, $row);
        $this->assertDatabaseCount('travel_booking_request_quote_versions', 1);
        self::assertSame('customer', DB::table('travel_booking_request_quote_versions')->value('accepted_via'));
        self::assertNotNull(DB::table('travel_booking_request_quote_versions')->value('accepted_at_utc'));
    }

    public function test_another_customer_cannot_view_or_accept_price(): void
    {
        [$tour, $departure] = $this->readyTour('quote-owner');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $intruder = User::factory()->create(['email_verified_at' => now()]);
        $row = $this->requests()->createCustomer($owner, $tour, $departure, 'standard-bdt', 1, 0)->request;
        $this->expectException(HttpException::class);
        $this->quotes()->previewCustomer($intruder, $row);
    }

    public function test_guest_requires_verified_email_and_separate_access_token(): void
    {
        [$tour, $departure] = $this->readyTour('quote-guest');
        $receipt = $this->requests()->createGuest($tour, $departure,
            'standard-bdt', 1, 0, 'Guest Traveler', 'guest@example.com');
        $row = $receipt->request;
        $this->postJson('/booking-requests/guest/quote-review', [
            'reference' => $row->reference_key, 'access_token' => (string) $receipt->accessToken,
        ])->assertNotFound();
        $this->requests()->verifyGuest($row, (string) $receipt->verificationToken);
        $this->postJson('/booking-requests/guest/quote-review', [
            'reference' => $row->reference_key, 'access_token' => str_repeat('0', 64),
        ])->assertNotFound();
        $response = $this->postJson('/booking-requests/guest/quote-review', [
            'reference' => $row->reference_key, 'access_token' => (string) $receipt->accessToken,
        ])->assertOk()->assertJsonPath('quote.total_minor', '1500000')
            ->assertDontSee((string) $receipt->accessToken);
        $quote = $response->json('quote');
        self::assertIsArray($quote);
        $this->postJson('/booking-requests/guest/quote-accept', [
            'reference' => $row->reference_key, 'access_token' => (string) $receipt->accessToken,
            'revision' => $quote['revision'], 'fingerprint' => $quote['fingerprint'],
        ])->assertOk()->assertJsonPath('quote.accepted', true);
        self::assertSame('guest', DB::table('travel_booking_request_quote_versions')->value('accepted_via'));
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_customer_endpoints_require_session_and_ownership(): void
    {
        [$tour, $departure] = $this->readyTour('quote-http-owner');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $row = $this->requests()->createCustomer($owner, $tour, $departure, 'standard-bdt', 1, 0)->request;
        $uri = '/account/booking-requests/'.$row->reference_key.'/quote-review';
        $this->post($uri)->assertRedirect();
        $this->actingAs($other)->postJson($uri)->assertNotFound();
        $this->actingAs($owner)->postJson($uri)->assertOk()
            ->assertJsonPath('quote.accepted', false)->assertJsonPath('quote.nonbinding', true);
    }

    public function test_withdrawn_and_expired_requests_cannot_be_accepted(): void
    {
        [$tour, $departure] = $this->readyTour('quote-inactive');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $row = $this->requests()->createCustomer($owner, $tour, $departure, 'standard-bdt', 1, 0)->request;
        $quote = $this->quotes()->previewCustomer($owner, $row);
        $this->requests()->withdrawCustomer($owner, $row);
        $this->expectException(ValidationException::class);
        $this->quotes()->acceptCustomer($owner, $row, (int) $quote['revision'], (string) $quote['fingerprint']);
    }

    public function test_guest_token_never_becomes_a_price_authority(): void
    {
        [$tour, $departure] = $this->readyTour('quote-no-price-input');
        $receipt = $this->requests()->createGuest($tour, $departure, 'standard-bdt',
            1, 0, 'Guest Traveler', 'guest2@example.com');
        $this->requests()->verifyGuest($receipt->request, (string) $receipt->verificationToken);
        $this->postJson('/booking-requests/guest/quote-accept', [
            'reference' => $receipt->request->reference_key,
            'access_token' => (string) $receipt->accessToken,
            'revision' => 999, 'fingerprint' => str_repeat('a', 64),
            'total_minor' => 1, 'currency' => 'USD',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
    }
}
