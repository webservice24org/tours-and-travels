<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingLifecycleManager;
use App\Domain\Travel\Bookings\TourBookingOfflinePaymentReconciler;
use App\Domain\Travel\Bookings\TourBookingQuoteAcceptanceManager;
use App\Domain\Travel\Bookings\TourBookingRequestExpiryManager;
use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Models\Travel\TourBookingRequest;
use App\Models\Travel\TourPriceVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Bablys\TourBookingQaTestCase;

/** P3E.6B: final cross-boundary lifecycle regression on isolated test database. */
final class BookingFinalLifecycleIntegrationTest extends TourBookingQaTestCase
{
    private function requestManager6b(): TourBookingRequestManager
    {
        return app(TourBookingRequestManager::class);
    }

    private function quoteManager6b(): TourBookingQuoteAcceptanceManager
    {
        return app(TourBookingQuoteAcceptanceManager::class);
    }

    private function lifecycle6b(): TourBookingLifecycleManager
    {
        return app(TourBookingLifecycleManager::class);
    }

    /** @return array<string,int|string|bool> */
    private function httpPayload6b(int $tourId, int $departureId): array
    {
        return [
            'tour_id' => $tourId, 'departure_id' => $departureId,
            'variant_code' => 'standard-bdt', 'adults' => 2, 'children' => 1,
            'consent' => true, 'submission_id' => (string) Str::uuid(),
        ];
    }

    public function test_customer_http_request_approval_conversion_confirmation_and_retry(): void
    {
        config()->set('bablys_booking_payment.timeout_enabled', false);
        config()->set('bablys_booking_payment.manual_reconciliation_enabled', false);
        [$tour, $departure] = $this->readyTour('p3e6b-customer');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $staff = $this->staff('administrator');
        $this->actingAs($owner)->post('/booking-requests', $this->httpPayload6b(
            (int) $tour->id, (int) $departure->id
        ))->assertRedirect('/booking-requests/submitted');
        $row = TourBookingRequest::query()->firstOrFail();
        self::assertSame('verified', $row->status);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $uri = '/account/booking-requests/'.$row->reference_key;
        $response = $this->postJson($uri.'/quote-review')->assertOk()
            ->assertJsonPath('quote.total_minor', '3800000')
            ->assertJsonPath('quote.inventory_reserved', false);
        $quote = $response->json('quote');
        self::assertIsArray($quote);
        $this->postJson($uri.'/quote-accept', [
            'revision' => $quote['revision'], 'fingerprint' => $quote['fingerprint'],
        ])->assertOk()->assertJsonPath('quote.accepted', true);
        $booking = $this->requestManager6b()->convertVerified($staff, $row);
        self::assertSame((int) $booking->id,
            (int) $this->requestManager6b()->convertVerified($staff, $row)->id);
        self::assertSame(3800000, (int) $booking->total_minor);
        self::assertSame('BDT', $booking->currency);
        self::assertSame('not_collected', $booking->payment_state);
        self::assertSame(3, (int) $departure->fresh()->seats_committed);
        $this->lifecycle6b()->confirm($staff, $booking);
        self::assertSame('confirmed', $booking->fresh()?->status);
        $this->assertDatabaseCount('travel_tour_bookings', 1);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 1);
        $this->assertDatabaseCount('travel_tour_booking_offline_payments', 0);
    }

    public function test_verified_guest_quote_can_convert_without_public_reservation_or_payment(): void
    {
        [$tour, $departure] = $this->readyTour('p3e6b-guest');
        $receipt = $this->requestManager6b()->createGuest(
            $tour, $departure, 'standard-bdt', 1, 0, 'Guest Traveler', 'guest-p3e6@example.com'
        );
        $this->requestManager6b()->verifyGuest($receipt->request, (string) $receipt->verificationToken);
        $proof = [
            'reference' => $receipt->request->reference_key,
            'access_token' => (string) $receipt->accessToken,
        ];
        $response = $this->postJson('/booking-requests/guest/quote-review', $proof)
            ->assertOk()->assertJsonPath('quote.total_minor', '1500000');
        $quote = $response->json('quote');
        self::assertIsArray($quote);
        $this->postJson('/booking-requests/guest/quote-accept', array_merge($proof, [
            'revision' => $quote['revision'], 'fingerprint' => $quote['fingerprint'],
        ]))->assertOk()->assertJsonPath('quote.accepted', true);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $booking = $this->requestManager6b()->convertVerified($this->staff('administrator'), $receipt->request);
        self::assertSame(1500000, (int) $booking->total_minor);
        self::assertSame('pending_confirmation', $booking->status);
        self::assertSame('not_collected', $booking->payment_state);
        self::assertSame(1, (int) $departure->fresh()->seats_committed);
    }

    public function test_repricing_demands_fresh_acceptance_and_final_snapshot_matches(): void
    {
        [$tour, $departure] = $this->readyTour('p3e6b-reprice');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $staff = $this->staff('administrator');
        $row = $this->requestManager6b()->createCustomer(
            $owner, $tour, $departure, 'standard-bdt', 1, 0
        )->request;
        $old = $this->quoteManager6b()->previewCustomer($owner, $row);
        $this->quoteManager6b()->acceptCustomer(
            $owner, $row, (int) $old['revision'], (string) $old['fingerprint']
        );
        $variant = TourPriceVariant::query()->where('tour_id', $tour->id)->firstOrFail();
        $variant->rates()->where('traveler_type', 'adult')->update(['regular_minor' => 2400000]);
        try {
            $this->requestManager6b()->convertVerified($staff, $row);
            self::fail('Old approval must never convert at a new price.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('travel_tour_bookings', 0);
            self::assertSame(0, (int) $departure->fresh()->seats_committed);
        }
        $new = $this->quoteManager6b()->previewCustomer($owner, $row);
        self::assertNotSame($old['fingerprint'], $new['fingerprint']);
        self::assertSame('2400000', $new['total_minor']);
        $this->quoteManager6b()->acceptCustomer(
            $owner, $row, (int) $new['revision'], (string) $new['fingerprint']
        );
        $booking = $this->requestManager6b()->convertVerified($staff, $row);
        self::assertSame(2400000, (int) $booking->total_minor);
        self::assertSame(2, DB::table('travel_booking_request_quote_versions')->count());
    }

    public function test_expired_request_cannot_hold_seats_even_after_previous_review(): void
    {
        [$tour, $departure] = $this->readyTour('p3e6b-expired');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $row = $this->requestManager6b()->createCustomer(
            $owner, $tour, $departure, 'standard-bdt', 1, 0
        )->request;
        $review = $this->quoteManager6b()->previewCustomer($owner, $row);
        $this->quoteManager6b()->acceptCustomer(
            $owner, $row, (int) $review['revision'], (string) $review['fingerprint']
        );
        $row->expires_at_utc = now('UTC')->subMinute();
        $row->save();
        $expired = app(TourBookingRequestExpiryManager::class)->expireBatch(10);
        self::assertSame(1, $expired['expired']);
        self::assertSame('expired', $row->fresh()?->status);
        try {
            $this->requestManager6b()->convertVerified($this->staff('administrator'), $row);
            self::fail('Expired request must not convert.');
        } catch (ValidationException) {
            self::assertSame(0, (int) $departure->fresh()->seats_committed);
            $this->assertDatabaseCount('travel_tour_bookings', 0);
        }
    }

    public function test_unpaid_confirmed_booking_cancellation_releases_exact_seats_once(): void
    {
        [$tour, $departure] = $this->readyTour('p3e6b-cancel');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $staff = $this->staff('administrator');
        $row = $this->requestManager6b()->createCustomer(
            $owner, $tour, $departure, 'standard-bdt', 2, 0
        )->request;
        $review = $this->quoteManager6b()->previewCustomer($owner, $row);
        $this->quoteManager6b()->acceptCustomer(
            $owner, $row, (int) $review['revision'], (string) $review['fingerprint']
        );
        $booking = $this->requestManager6b()->convertVerified($staff, $row);
        $this->lifecycle6b()->confirm($staff, $booking);
        self::assertSame(2, (int) $departure->fresh()->seats_committed);
        $this->lifecycle6b()->cancel($staff, $booking);
        $this->lifecycle6b()->cancel($staff, $booking);
        self::assertSame('cancelled', $booking->fresh()?->status);
        self::assertSame('released', (string) $booking->fresh()?->allocation?->status);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 1);
    }

    public function test_paid_booking_cannot_cancel_without_approved_refund_policy(): void
    {
        config()->set('bablys_booking_payment.manual_reconciliation_enabled', true);
        [$tour, $departure] = $this->readyTour('p3e6b-paid');
        $staff = $this->staff('administrator');
        $booking = $this->lifecycle6b()->createInternal(
            $staff, $tour, $departure, 'BOOK-P3E6-PAID-0001', 'standard-bdt', 1
        );
        app(TourBookingOfflinePaymentReconciler::class)->recordExternallyVerifiedFullPayment(
            $staff, $booking, 1500000, 'BDT', 'P3E6-BANK-EXTERNAL-0001'
        );
        try {
            $this->lifecycle6b()->cancel($staff, $booking);
            self::fail('Cancellation of paid booking must be blocked.');
        } catch (ValidationException) {
            self::assertSame('paid', $booking->fresh()?->payment_state);
            self::assertSame('pending_confirmation', $booking->fresh()?->status);
            self::assertSame(1, (int) $departure->fresh()->seats_committed);
        }
    }

    public function test_unprivileged_staff_cannot_convert_approved_request(): void
    {
        [$tour, $departure] = $this->readyTour('p3e6b-permission');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $row = $this->requestManager6b()->createCustomer(
            $owner, $tour, $departure, 'standard-bdt', 1, 0
        )->request;
        $review = $this->quoteManager6b()->previewCustomer($owner, $row);
        $this->quoteManager6b()->acceptCustomer(
            $owner, $row, (int) $review['revision'], (string) $review['fingerprint']
        );
        try {
            $this->requestManager6b()->convertVerified(User::factory()->create(), $row);
            self::fail('Unauthorized conversion must be rejected.');
        } catch (HttpException) {
            self::assertSame('verified', $row->fresh()?->status);
            self::assertSame(0, (int) $departure->fresh()->seats_committed);
            $this->assertDatabaseCount('travel_tour_bookings', 0);
        }
    }
}
