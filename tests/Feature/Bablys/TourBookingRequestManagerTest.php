<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingLifecycleManager;
use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Domain\Travel\Bookings\TourBookingQuoteAcceptanceManager;
use App\Models\Cms\CmsRole;
use App\Models\Travel\TourBooking;
use App\Models\Travel\TourBookingRequest;
use App\Models\Travel\TourPriceVariant;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Bablys\TourBookingQaTestCase;

final class TourBookingRequestManagerTest extends TourBookingQaTestCase
{
    private function service(): TourBookingRequestManager
    {
        return app(TourBookingRequestManager::class);
    }

    private function approve(User $owner, TourBookingRequest $row): void
    {
        $quotes = app(TourBookingQuoteAcceptanceManager::class);
        $current = $quotes->previewCustomer($owner, $row);
        $quotes->acceptCustomer($owner, $row,
            (int) $current['revision'], (string) $current['fingerprint']);
    }

    public function test_guest_request_is_unverified_and_reserves_no_seats_or_booking(): void
    {
        [$tour, $departure] = $this->readyTour('request-guest');
        $receipt = $this->service()->createGuest(
            $tour, $departure, 'standard-bdt', 2, 1, 'Test Guest', 'Guest@Example.com', '+8801712345678'
        );
        self::assertSame('awaiting_verification', $receipt->request->status);
        self::assertNotNull($receipt->verificationToken);
        self::assertNotNull($receipt->accessToken);
        self::assertSame('guest@example.com', Crypt::decryptString($receipt->request->contact_email_encrypted));
        self::assertNotSame('guest@example.com', $receipt->request->contact_email_encrypted);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_guest_receipt_cannot_accidentally_serialize_its_raw_tokens(): void
    {
        [$tour, $departure] = $this->readyTour('request-private-receipt');
        $receipt = $this->service()->createGuest(
            $tour, $departure, 'standard-bdt', 1, 0, 'Private Guest', 'private@example.com'
        );
        $this->expectException(\LogicException::class);
        json_encode($receipt, JSON_THROW_ON_ERROR);
    }

    public function test_guest_verification_is_one_time_and_does_not_commit_seats(): void
    {
        [$tour, $departure] = $this->readyTour('request-verify');
        $receipt = $this->service()->createGuest(
            $tour, $departure, 'standard-bdt', 1, 0, 'Test Guest', 'guest@example.com'
        );
        $verified = $this->service()->verifyGuest($receipt->request, (string) $receipt->verificationToken);
        self::assertSame('verified', $verified->status);
        self::assertNull($verified->verification_token_hash);
        self::assertNotNull($verified->verified_at_utc);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->expectException(ValidationException::class);
        $this->service()->verifyGuest($receipt->request, (string) $receipt->verificationToken);
    }

    public function test_wrong_guest_token_is_rejected_without_verification(): void
    {
        [$tour, $departure] = $this->readyTour('request-wrong');
        $receipt = $this->service()->createGuest(
            $tour, $departure, 'standard-bdt', 1, 0, 'Test Guest', 'guest@example.com'
        );
        $this->expectException(ValidationException::class);
        try {
            $this->service()->verifyGuest($receipt->request, str_repeat('0', 64));
        } finally {
            self::assertSame('awaiting_verification', $receipt->request->fresh()->status);
        }
    }

    public function test_expired_guest_verification_token_is_rejected(): void
    {
        [$tour, $departure] = $this->readyTour('request-expired-token');
        $receipt = $this->service()->createGuest(
            $tour, $departure, 'standard-bdt', 1, 0, 'Test Guest', 'guest@example.com'
        );
        $receipt->request->verification_expires_at_utc = now('UTC')->subMinute();
        $receipt->request->save();
        $this->expectException(ValidationException::class);
        $this->service()->verifyGuest($receipt->request, (string) $receipt->verificationToken);
    }

    public function test_guest_may_withdraw_using_access_secret_and_reference_is_retained(): void
    {
        [$tour, $departure] = $this->readyTour('request-guest-withdraw');
        $receipt = $this->service()->createGuest(
            $tour, $departure, 'standard-bdt', 1, 0, 'Test Guest', 'guest@example.com'
        );
        $withdrawn = $this->service()->withdrawGuest($receipt->request, (string) $receipt->accessToken);
        self::assertSame('withdrawn', $withdrawn->status);
        self::assertNull($withdrawn->verification_token_hash);
        self::assertNull($withdrawn->access_token_hash);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_booking_requests', 1);
    }

    public function test_wrong_access_token_cannot_withdraw_guest_request(): void
    {
        [$tour, $departure] = $this->readyTour('request-wrong-access');
        $receipt = $this->service()->createGuest(
            $tour, $departure, 'standard-bdt', 1, 0, 'Test Guest', 'guest@example.com'
        );
        $this->expectException(ValidationException::class);
        $this->service()->withdrawGuest($receipt->request, bin2hex(random_bytes(32)));
    }

    public function test_verified_customer_request_is_owned_and_does_not_need_guest_tokens(): void
    {
        [$tour, $departure] = $this->readyTour('request-customer');
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $receipt = $this->service()->createCustomer($customer, $tour, $departure, 'standard-bdt', 2, 0);
        self::assertSame('verified', $receipt->request->status);
        self::assertSame((int) $customer->getKey(), (int) $receipt->request->customer_id);
        self::assertNull($receipt->verificationToken);
        self::assertNull($receipt->accessToken);
        self::assertNotNull($receipt->request->verified_at_utc);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
    }

    public function test_unverified_customer_cannot_bypass_proof(): void
    {
        [$tour, $departure] = $this->readyTour('request-unverified');
        $customer = User::factory()->create(['email_verified_at' => null]);
        $this->expectException(HttpException::class);
        $this->service()->createCustomer($customer, $tour, $departure, 'standard-bdt', 1, 0);
    }

    public function test_another_customer_cannot_withdraw_an_owned_request(): void
    {
        [$tour, $departure] = $this->readyTour('request-other-user');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $receipt = $this->service()->createCustomer($owner, $tour, $departure, 'standard-bdt', 1, 0);
        $this->expectException(HttpException::class);
        try {
            $this->service()->withdrawCustomer($other, $receipt->request);
        } finally {
            self::assertSame('verified', $receipt->request->fresh()->status);
        }
    }

    public function test_customer_may_withdraw_their_unconverted_request(): void
    {
        [$tour, $departure] = $this->readyTour('request-customer-withdraw');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $receipt = $this->service()->createCustomer($owner, $tour, $departure, 'standard-bdt', 1, 0);
        $withdrawn = $this->service()->withdrawCustomer($owner, $receipt->request);
        self::assertSame('withdrawn', $withdrawn->status);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_staff_conversion_allocates_once_and_snapshots_server_price(): void
    {
        [$tour, $departure] = $this->readyTour('request-convert');
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $staff = $this->staff('administrator');
        $receipt = $this->service()->createCustomer($customer, $tour, $departure, 'standard-bdt', 2, 1);
        $this->approve($customer, $receipt->request);
        $one = $this->service()->convertVerified($staff, $receipt->request);
        $two = $this->service()->convertVerified($staff, $receipt->request);
        self::assertSame($one->getKey(), $two->getKey());
        self::assertSame('pending_confirmation', $one->status);
        self::assertSame(3800000, (int) $one->total_minor);
        self::assertSame(3, (int) $departure->fresh()->seats_committed);
        self::assertSame('converted', $receipt->request->fresh()->status);
        $this->assertDatabaseCount('travel_tour_bookings', 1);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 1);
    }

    public function test_unverified_guest_cannot_be_converted_by_staff(): void
    {
        [$tour, $departure] = $this->readyTour('request-noverify');
        $receipt = $this->service()->createGuest(
            $tour, $departure, 'standard-bdt', 1, 0, 'Test Guest', 'guest@example.com'
        );
        $this->expectException(ValidationException::class);
        try {
            $this->service()->convertVerified($this->staff('administrator'), $receipt->request);
        } finally {
            $this->assertDatabaseCount('travel_tour_bookings', 0);
            self::assertSame(0, (int) $departure->fresh()->seats_committed);
        }
    }

    public function test_conversion_recalculates_price_after_request_creation(): void
    {
        [$tour, $departure] = $this->readyTour('request-price-changed');
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $receipt = $this->service()->createCustomer($customer, $tour, $departure, 'standard-bdt', 1, 0);
        $variant = TourPriceVariant::query()->where('tour_id', $tour->getKey())->firstOrFail();
        $variant->rates()->where('traveler_type', 'adult')->update(['regular_minor' => 2000000]);
        $this->approve($customer, $receipt->request);
        $booking = $this->service()->convertVerified($this->staff('administrator'), $receipt->request);
        self::assertSame(2000000, (int) $booking->total_minor);
    }

    public function test_conversion_rechecks_capacity_and_rolls_back_without_changing_request(): void
    {
        [$tour, $departure] = $this->readyTour('request-race-check');
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $receipt = $this->service()->createCustomer($customer, $tour, $departure, 'standard-bdt', 10, 0);
        $this->approve($customer, $receipt->request);
        app(TourBookingLifecycleManager::class)->createInternal(
            $this->staff('administrator'), $tour, $departure, 'BOOK-used-one-seat', 'standard-bdt', 1
        );
        $this->expectException(ValidationException::class);
        try {
            $this->service()->convertVerified($this->staff('administrator'), $receipt->request);
        } finally {
            self::assertSame(1, (int) $departure->fresh()->seats_committed);
            self::assertSame('verified', $receipt->request->fresh()->status);
            $this->assertDatabaseCount('travel_tour_bookings', 1);
        }
    }

    public function test_expired_intent_cannot_be_converted_or_hold_seats(): void
    {
        [$tour, $departure] = $this->readyTour('request-expired');
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $receipt = $this->service()->createCustomer($customer, $tour, $departure, 'standard-bdt', 1, 0);
        $receipt->request->expires_at_utc = now('UTC')->subMinute();
        $receipt->request->save();
        $this->expectException(ValidationException::class);
        $this->service()->convertVerified($this->staff('administrator'), $receipt->request);
    }

    public function test_revoked_staff_permissions_cannot_convert_verified_customer(): void
    {
        [$tour, $departure] = $this->readyTour('request-staff-revoke');
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $receipt = $this->service()->createCustomer($customer, $tour, $departure, 'standard-bdt', 1, 0);
        $staff = $this->staff('administrator');
        CmsRole::query()->where('key', 'administrator')->firstOrFail()->users()->detach($staff->getKey());
        $this->expectException(HttpException::class);
        $this->service()->convertVerified($staff, $receipt->request);
    }

    public function test_customer_cannot_withdraw_after_real_booking_conversion(): void
    {
        [$tour, $departure] = $this->readyTour('request-already-booked');
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $receipt = $this->service()->createCustomer($customer, $tour, $departure, 'standard-bdt', 1, 0);
        $this->approve($customer, $receipt->request);
        $this->service()->convertVerified($this->staff('administrator'), $receipt->request);
        $this->expectException(ValidationException::class);
        $this->service()->withdrawCustomer($customer, $receipt->request);
    }

    public function test_invalid_contact_and_input_do_not_create_any_request(): void
    {
        [$tour, $departure] = $this->readyTour('request-inputs');
        $this->expectException(ValidationException::class);
        try {
            $this->service()->createGuest(
                $tour, $departure, 'standard-bdt', 1, 0, '<script>name</script>', 'bad-email'
            );
        } finally {
            $this->assertDatabaseCount('travel_tour_booking_requests', 0);
        }
    }
}
