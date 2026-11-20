<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingRequestExpiryManager;
use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Models\Travel\TourBookingRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bablys\TourBookingQaTestCase;

final class TourBookingRequestExpiryTest extends TourBookingQaTestCase
{
    private function expiry(): TourBookingRequestExpiryManager
    {
        return app(TourBookingRequestExpiryManager::class);
    }

    private function requests(): TourBookingRequestManager
    {
        return app(TourBookingRequestManager::class);
    }

    public function test_unverified_guest_expires_at_email_proof_deadline_and_both_secrets_are_revoked(): void
    {
        [$tour, $departure] = $this->readyTour('expire-guest-proof');
        $receipt = $this->requests()->createGuest(
            $tour,
            $departure,
            'standard-bdt',
            2,
            0,
            'Example Guest',
            'guest@example.com'
        );
        $row = $receipt->request;
        $row->verification_expires_at_utc = now('UTC')->subMinute();
        $row->save();

        self::assertSame(1, $this->expiry()->dueCount());
        $result = $this->expiry()->expireBatch(10);
        self::assertSame(1, $result['expired']);
        self::assertSame(1, $result['verification_timeout']);
        self::assertSame(0, $result['request_timeout']);
        $expired = $row->fresh();
        self::assertNotNull($expired);
        self::assertSame('expired', $expired->status);
        self::assertNull($expired->verification_token_hash);
        self::assertNull($expired->access_token_hash);
        $this->assertDatabaseHas('travel_tour_booking_requests', [
            'id' => $row->getKey(),
            'expired_reason' => 'verification_timeout',
        ]);
        self::assertNotNull(DB::table('travel_tour_booking_requests')
            ->where('id', $row->getKey())->value('expired_at_utc'));
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_guest_token_cannot_verify_after_expiry_sweep(): void
    {
        [$tour, $departure] = $this->readyTour('expire-guest-proof-block');
        $receipt = $this->requests()->createGuest(
            $tour,
            $departure,
            'standard-bdt',
            1,
            0,
            'Example Guest',
            'guest@example.com'
        );
        $row = $receipt->request;
        $row->verification_expires_at_utc = now('UTC')->subMinute();
        $row->save();
        $this->expiry()->expireBatch();

        $this->expectException(ValidationException::class);
        $this->requests()->verifyGuest($row, (string) $receipt->verificationToken);
    }

    public function test_verified_customer_expires_at_request_deadline_without_booking_or_seat_release(): void
    {
        [$tour, $departure] = $this->readyTour('expire-customer-request');
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $row = $this->requests()->createCustomer(
            $customer,
            $tour,
            $departure,
            'standard-bdt',
            2,
            0
        )->request;
        $row->expires_at_utc = now('UTC')->subMinute();
        $row->save();

        $result = $this->expiry()->expireBatch(10);
        self::assertSame(1, $result['request_timeout']);
        self::assertSame('expired', $row->fresh()?->status);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_verified_guest_is_not_expired_by_old_verification_deadline(): void
    {
        [$tour, $departure] = $this->readyTour('expire-verified-guest');
        $receipt = $this->requests()->createGuest(
            $tour,
            $departure,
            'standard-bdt',
            1,
            0,
            'Example Guest',
            'guest@example.com'
        );
        $row = $this->requests()->verifyGuest($receipt->request, (string) $receipt->verificationToken);
        $row->verification_expires_at_utc = now('UTC')->subMinute();
        $row->save();

        self::assertSame(0, $this->expiry()->dueCount());
        self::assertSame(0, $this->expiry()->expireBatch()['expired']);
        self::assertSame('verified', $row->fresh()?->status);
    }

    public function test_expiry_does_not_touch_withdrawn_requests(): void
    {
        [$tour, $departure] = $this->readyTour('expire-withdrawn');
        $receipt = $this->requests()->createGuest(
            $tour,
            $departure,
            'standard-bdt',
            1,
            0,
            'Example Guest',
            'guest@example.com'
        );
        $row = $this->requests()->withdrawGuest($receipt->request, (string) $receipt->accessToken);
        $row->expires_at_utc = now('UTC')->subMinute();
        $row->save();

        self::assertSame(0, $this->expiry()->dueCount());
        self::assertSame(0, $this->expiry()->expireBatch()['expired']);
        self::assertSame('withdrawn', $row->fresh()?->status);
        self::assertNull(DB::table('travel_tour_booking_requests')
            ->where('id', $row->getKey())->value('expired_at_utc'));
    }

    public function test_converted_request_is_immutable_and_committed_booking_is_untouched(): void
    {
        [$tour, $departure] = $this->readyTour('expire-converted');
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $row = $this->requests()->createCustomer(
            $customer,
            $tour,
            $departure,
            'standard-bdt',
            1,
            0
        )->request;

        // P3E.5B.3B: Traveler must accept the current quote.
        $quotes = app(
            \App\Domain\Travel\Bookings\TourBookingQuoteAcceptanceManager::class
        );

        $current = $quotes->previewCustomer($customer, $row);

        $quotes->acceptCustomer(
            $customer,
            $row,
            (int) $current['revision'],
            (string) $current['fingerprint']
        );

        // Staff conversion is now permitted.
        $booking = $this->requests()->convertVerified(
            $this->staff('administrator'),
            $row
        );
        $row->expires_at_utc = now('UTC')->subMinute();
        $row->save();

        self::assertSame(0, $this->expiry()->dueCount());
        self::assertSame(0, $this->expiry()->expireBatch()['expired']);
        self::assertSame('converted', $row->fresh()?->status);
        self::assertSame('not_collected', $booking->fresh()?->payment_state);
        self::assertSame('pending_confirmation', $booking->fresh()?->status);
        self::assertSame(1, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_bookings', 1);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 1);
    }

    public function test_sweep_is_idempotent_and_retains_original_reference_and_submission_key(): void
    {
        [$tour, $departure] = $this->readyTour('expire-idempotent');
        $receipt = $this->requests()->createGuest(
            $tour,
            $departure,
            'standard-bdt',
            1,
            0,
            'Example Guest',
            'guest@example.com'
        );
        $row = $receipt->request;
        DB::table('travel_tour_booking_requests')->where('id', $row->getKey())->update([
            'submission_key_hash' => str_repeat('a', 64),
            'expires_at_utc' => now('UTC')->subMinute()->format('Y-m-d H:i:s'),
        ]);
        $reference = (string) $row->reference_key;

        self::assertSame(1, $this->expiry()->expireBatch()['expired']);
        self::assertSame(0, $this->expiry()->expireBatch()['expired']);
        $fresh = TourBookingRequest::query()->findOrFail($row->getKey());
        self::assertSame($reference, (string) $fresh->reference_key);
        self::assertSame('expired', (string) $fresh->status);
        self::assertSame(str_repeat('a', 64), (string) $fresh->getAttribute('submission_key_hash'));
    }

    public function test_batch_limit_is_bounded_and_second_run_catches_remaining_request(): void
    {
        [$tour, $departure] = $this->readyTour('expire-batch-limit');
        $first = $this->requests()->createGuest(
            $tour,
            $departure,
            'standard-bdt',
            1,
            0,
            'First Guest',
            'first@example.com'
        )->request;
        $second = $this->requests()->createGuest(
            $tour,
            $departure,
            'standard-bdt',
            1,
            0,
            'Second Guest',
            'second@example.com'
        )->request;
        TourBookingRequest::query()->whereKey($first->getKey())->update([
            'verification_expires_at_utc' => now('UTC')->subMinute()->format('Y-m-d H:i:s'),
        ]);
        TourBookingRequest::query()->whereKey($second->getKey())->update([
            'verification_expires_at_utc' => now('UTC')->subMinute()->format('Y-m-d H:i:s'),
        ]);

        self::assertSame(2, $this->expiry()->dueCount());
        self::assertSame(1, $this->expiry()->expireBatch(1)['expired']);
        self::assertSame(1, $this->expiry()->dueCount());
        self::assertSame(1, $this->expiry()->expireBatch(1)['expired']);
        self::assertSame(0, $this->expiry()->dueCount());
    }

    public function test_dry_run_command_has_no_side_effects(): void
    {
        [$tour, $departure] = $this->readyTour('expire-dry-run');
        $row = $this->requests()->createGuest(
            $tour,
            $departure,
            'standard-bdt',
            1,
            0,
            'Example Guest',
            'guest@example.com'
        )->request;
        $row->verification_expires_at_utc = now('UTC')->subMinute();
        $row->save();

        $this->artisan('bablys:bookings:expire-requests', [
            '--limit' => '10',
            '--dry-run' => true,
        ])->assertExitCode(0);
        self::assertSame('awaiting_verification', $row->fresh()?->status);
        self::assertSame(1, $this->expiry()->dueCount());
    }

    public function test_command_expires_due_requests_and_validates_limit(): void
    {
        [$tour, $departure] = $this->readyTour('expire-command');
        $row = $this->requests()->createGuest(
            $tour,
            $departure,
            'standard-bdt',
            1,
            0,
            'Example Guest',
            'guest@example.com'
        )->request;
        $row->verification_expires_at_utc = now('UTC')->subMinute();
        $row->save();

        $this->artisan('bablys:bookings:expire-requests', ['--limit' => '10'])->assertExitCode(0);
        self::assertSame('expired', $row->fresh()?->status);
        $this->artisan('bablys:bookings:expire-requests', ['--limit' => '0'])->assertExitCode(1);
        $this->artisan('bablys:bookings:expire-requests', ['--limit' => '2001'])->assertExitCode(1);
    }

    public function test_unexpired_request_is_not_expired_early(): void
    {
        [$tour, $departure] = $this->readyTour('expire-not-due');
        $row = $this->requests()->createGuest(
            $tour,
            $departure,
            'standard-bdt',
            1,
            0,
            'Example Guest',
            'guest@example.com'
        )->request;

        self::assertSame(0, $this->expiry()->dueCount());
        self::assertSame(0, $this->expiry()->expireBatch()['expired']);
        self::assertSame('awaiting_verification', $row->fresh()?->status);
    }

    public function test_expired_verified_request_cannot_be_converted_into_seat_hold(): void
    {
        [$tour, $departure] = $this->readyTour('expire-no-convert');
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $row = $this->requests()->createCustomer(
            $customer,
            $tour,
            $departure,
            'standard-bdt',
            1,
            0
        )->request;
        $row->expires_at_utc = now('UTC')->subMinute();
        $row->save();
        $this->expiry()->expireBatch();

        $this->expectException(ValidationException::class);
        try {
            $this->requests()->convertVerified($this->staff('administrator'), $row);
        } finally {
            self::assertSame(0, (int) $departure->fresh()->seats_committed);
            $this->assertDatabaseCount('travel_tour_bookings', 0);
        }
    }
}
