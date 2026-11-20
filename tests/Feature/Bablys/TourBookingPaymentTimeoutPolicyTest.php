<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingLifecycleManager;
use App\Domain\Travel\Bookings\TourBookingOfflinePaymentReconciler;
use App\Domain\Travel\Bookings\TourBookingTimeoutPolicy;
use App\Models\Travel\TourBooking;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Bablys\TourBookingQaTestCase;

final class TourBookingPaymentTimeoutPolicyTest extends TourBookingQaTestCase
{
    private function bookingLifecycle3c(): TourBookingLifecycleManager
    {
        return app(TourBookingLifecycleManager::class);
    }

    private function bookingTimeout3c(): TourBookingTimeoutPolicy
    {
        return app(TourBookingTimeoutPolicy::class);
    }

    private function bookingOffline3c(): TourBookingOfflinePaymentReconciler
    {
        return app(TourBookingOfflinePaymentReconciler::class);
    }

    private function setBookingDue3c(TourBooking $booking): void
    {
        $booking->setAttribute('confirmation_due_at_utc', now('UTC')->subMinute()->format('Y-m-d H:i:s'));
        $booking->save();
    }

    public function test_disabled_default_does_not_introduce_a_deadline_or_payment(): void
    {
        config()->set('bablys_booking_payment.timeout_enabled', false);
        config()->set('bablys_booking_payment.manual_reconciliation_enabled', false);
        [$tour, $departure] = $this->readyTour('c-no-default-mutations');
        $booking = $this->bookingLifecycle3c()->createInternal(
            $this->staff('administrator'), $tour, $departure, 'BOOK-3C-no-default-001', 'standard-bdt', 1
        );
        self::assertNull($booking->fresh()?->getRawOriginal('confirmation_due_at_utc'));
        self::assertSame('not_collected', $booking->fresh()?->payment_state);
        self::assertSame(0, $this->bookingTimeout3c()->dueCount());
        $this->assertDatabaseCount('travel_tour_booking_offline_payments', 0);
    }

    public function test_opted_in_deadline_is_stamped_on_new_staff_booking_only(): void
    {
        config()->set('bablys_booking_payment.timeout_enabled', true);
        config()->set('bablys_booking_payment.pending_confirmation_hours', 24);
        [$tour, $departure] = $this->readyTour('c-opt-in-deadline');
        $booking = $this->bookingLifecycle3c()->createInternal(
            $this->staff('administrator'), $tour, $departure, 'BOOK-3C-opt-in-0001', 'standard-bdt', 2
        );
        $due = $booking->fresh()?->getRawOriginal('confirmation_due_at_utc');
        self::assertIsString($due);
        self::assertGreaterThan(now('UTC')->addHours(23)->format('Y-m-d H:i:s'), $due);
        self::assertLessThan(now('UTC')->addHours(25)->format('Y-m-d H:i:s'), $due);
        self::assertSame(2, (int) $departure->fresh()->seats_committed);
    }

    public function test_overdue_worker_flags_without_cancelling_or_releasing_committed_seats(): void
    {
        [$tour, $departure] = $this->readyTour('c-overdue-worker');
        $actor = $this->staff('administrator');
        $booking = $this->bookingLifecycle3c()->createInternal($actor, $tour, $departure, 'BOOK-3C-overdue-0001', 'standard-bdt', 2);
        $this->setBookingDue3c($booking);
        self::assertSame(1, $this->bookingTimeout3c()->dueCount());
        self::assertSame(1, $this->bookingTimeout3c()->flagOverdueBatch()['flagged']);
        self::assertSame(0, $this->bookingTimeout3c()->flagOverdueBatch()['flagged']);
        self::assertNotNull($booking->fresh()?->getRawOriginal('timeout_review_required_at_utc'));
        self::assertSame('pending_confirmation', $booking->fresh()?->status);
        self::assertSame('not_collected', $booking->fresh()?->payment_state);
        self::assertSame(2, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseHas('travel_tour_seat_allocations', [
            'reference_key' => 'BOOK-3C-overdue-0001', 'status' => 'committed',
        ]);
        $this->assertDatabaseCount('travel_tour_booking_offline_payments', 0);
    }

    public function test_confirmation_rejects_overdue_deadline_even_before_worker_marks_it(): void
    {
        [$tour, $departure] = $this->readyTour('c-confirm-block');
        $actor = $this->staff('administrator');
        $booking = $this->bookingLifecycle3c()->createInternal($actor, $tour, $departure, 'BOOK-3C-confirm-block', 'standard-bdt', 1);
        $this->setBookingDue3c($booking);
        try {
            $this->bookingLifecycle3c()->confirm($actor, $booking);
            self::fail('Overdue booking must not confirm without review.');
        } catch (ValidationException) {
            self::assertSame('pending_confirmation', $booking->fresh()?->status);
            self::assertSame(1, (int) $departure->fresh()->seats_committed);
        }
    }

    public function test_authorized_review_renews_pending_deadline_and_then_confirms_without_payment(): void
    {
        [$tour, $departure] = $this->readyTour('c-renew-then-confirm');
        $actor = $this->staff('administrator');
        $booking = $this->bookingLifecycle3c()->createInternal($actor, $tour, $departure, 'BOOK-3C-renew-confirm', 'standard-bdt', 1);
        $this->setBookingDue3c($booking);
        $this->bookingTimeout3c()->flagOverdueBatch();
        $this->bookingTimeout3c()->renewAfterReview($actor, $booking, 120);
        self::assertNull($booking->fresh()?->getRawOriginal('timeout_review_required_at_utc'));
        self::assertNotNull($booking->fresh()?->getRawOriginal('timeout_reviewed_at_utc'));
        $this->bookingLifecycle3c()->confirm($actor, $booking);
        self::assertSame('confirmed', $booking->fresh()?->status);
        self::assertSame('not_collected', $booking->fresh()?->payment_state);
    }

    public function test_review_cannot_reopen_without_existing_overdue_deadline(): void
    {
        [$tour, $departure] = $this->readyTour('c-review-not-due');
        $actor = $this->staff('administrator');
        $booking = $this->bookingLifecycle3c()->createInternal($actor, $tour, $departure, 'BOOK-3C-review-reject', 'standard-bdt', 1);
        $this->expectException(ValidationException::class);
        $this->bookingTimeout3c()->renewAfterReview($actor, $booking, 120);
    }

    public function test_customer_must_not_renew_pending_booking(): void
    {
        [$tour, $departure] = $this->readyTour('c-review-auth');
        $booking = $this->bookingLifecycle3c()->createInternal(
            $this->staff('administrator'), $tour, $departure, 'BOOK-3C-review-auth01', 'standard-bdt', 1
        );
        $this->setBookingDue3c($booking);
        $this->expectException(HttpException::class);
        $this->bookingTimeout3c()->renewAfterReview(User::factory()->create(), $booking, 120);
    }

    public function test_payment_reconciliation_is_off_by_default(): void
    {
        config()->set('bablys_booking_payment.manual_reconciliation_enabled', false);
        [$tour, $departure] = $this->readyTour('c-payment-disabled');
        $actor = $this->staff('administrator');
        $booking = $this->bookingLifecycle3c()->createInternal($actor, $tour, $departure, 'BOOK-3C-no-payments', 'standard-bdt', 1);
        $this->expectException(ValidationException::class);
        try {
            $this->bookingOffline3c()->recordExternallyVerifiedFullPayment(
                $actor, $booking, 1500000, 'BDT', 'BANK-RECEIPT-00311'
            );
        } finally {
            $this->assertDatabaseCount('travel_tour_booking_offline_payments', 0);
            self::assertSame('not_collected', $booking->fresh()?->payment_state);
        }
    }

    public function test_wrong_amount_and_currency_are_rejected_without_ledger_or_state_change(): void
    {
        config()->set('bablys_booking_payment.manual_reconciliation_enabled', true);
        [$tour, $departure] = $this->readyTour('c-payment-amount');
        $actor = $this->staff('administrator');
        $booking = $this->bookingLifecycle3c()->createInternal($actor, $tour, $departure, 'BOOK-3C-pay-amount-1', 'standard-bdt', 1);
        foreach ([[1500001, 'BDT'], [1500000, 'USD'], [0, 'BDT']] as [$amount, $currency]) {
            try {
                $this->bookingOffline3c()->recordExternallyVerifiedFullPayment(
                    $actor, $booking, $amount, $currency, 'BANK-RECEIPT-00312'
                );
                self::fail('Invalid amount/currency must be rejected.');
            } catch (ValidationException) {
                // The failed attempt must never produce partial financial writes.
            }
        }
        $this->assertDatabaseCount('travel_tour_booking_offline_payments', 0);
        self::assertSame('not_collected', $booking->fresh()?->payment_state);
    }

    public function test_paid_full_offline_reconciliation_is_single_idempotent_and_blocks_cancellation(): void
    {
        config()->set('bablys_booking_payment.manual_reconciliation_enabled', true);
        [$tour, $departure] = $this->readyTour('c-payment-idempotent');
        $actor = $this->staff('administrator');
        $booking = $this->bookingLifecycle3c()->createInternal($actor, $tour, $departure, 'BOOK-3C-pay-idempot', 'standard-bdt', 1);
        $one = $this->bookingOffline3c()->recordExternallyVerifiedFullPayment(
            $actor, $booking, 1500000, 'BDT', 'BANK-RECEIPT-00313'
        );
        $two = $this->bookingOffline3c()->recordExternallyVerifiedFullPayment(
            $actor, $booking, 1500000, 'BDT', 'BANK-RECEIPT-00313'
        );
        self::assertSame($one->getKey(), $two->getKey());
        self::assertSame('paid', $booking->fresh()?->payment_state);
        $this->assertDatabaseCount('travel_tour_booking_offline_payments', 1);
        self::assertSame(1, DB::table('cms_audit_events')
            ->where('action', 'tour.booking.offline_payment_reconciled')->count());
        try {
            $this->bookingLifecycle3c()->cancel($actor, $booking);
            self::fail('Paid booking cancellation must be blocked until refund rules exist.');
        } catch (ValidationException) {
            self::assertSame(1, (int) $departure->fresh()->seats_committed);
            self::assertSame('pending_confirmation', $booking->fresh()?->status);
        }
    }

    public function test_reused_external_reference_on_different_booking_is_blocked(): void
    {
        config()->set('bablys_booking_payment.manual_reconciliation_enabled', true);
        [$tour, $departure] = $this->readyTour('c-payment-reuse');
        $actor = $this->staff('administrator');
        $a = $this->bookingLifecycle3c()->createInternal($actor, $tour, $departure, 'BOOK-3C-proof-first', 'standard-bdt', 1);
        $b = $this->bookingLifecycle3c()->createInternal($actor, $tour, $departure, 'BOOK-3C-proof-other', 'standard-bdt', 1);
        $this->bookingOffline3c()->recordExternallyVerifiedFullPayment($actor, $a, 1500000, 'BDT', 'BANK-RECEIPT-00314');
        $this->expectException(ValidationException::class);
        try {
            $this->bookingOffline3c()->recordExternallyVerifiedFullPayment($actor, $b, 1500000, 'BDT', 'BANK-RECEIPT-00314');
        } finally {
            self::assertSame('not_collected', $b->fresh()?->payment_state);
            $this->assertDatabaseCount('travel_tour_booking_offline_payments', 1);
        }
    }

    public function test_pending_payment_is_blocked_after_deadline(): void
    {
        config()->set('bablys_booking_payment.manual_reconciliation_enabled', true);
        [$tour, $departure] = $this->readyTour('c-payment-expired');
        $actor = $this->staff('administrator');
        $booking = $this->bookingLifecycle3c()->createInternal($actor, $tour, $departure, 'BOOK-3C-pay-expired', 'standard-bdt', 1);
        $this->setBookingDue3c($booking);
        $this->expectException(ValidationException::class);
        $this->bookingOffline3c()->recordExternallyVerifiedFullPayment($actor, $booking, 1500000, 'BDT', 'BANK-RECEIPT-00315');
    }

    public function test_command_dry_run_then_flags_due_bookings_without_cancelling(): void
    {
        [$tour, $departure] = $this->readyTour('c-command-run');
        $booking = $this->bookingLifecycle3c()->createInternal(
            $this->staff('administrator'), $tour, $departure, 'BOOK-3C-cmd-booking', 'standard-bdt', 1
        );
        $this->setBookingDue3c($booking);
        $this->artisan('bablys:bookings:flag-overdue', ['--dry-run' => true])->assertExitCode(0);
        self::assertNull($booking->fresh()?->getRawOriginal('timeout_review_required_at_utc'));
        $this->artisan('bablys:bookings:flag-overdue', ['--limit' => '10'])->assertExitCode(0);
        self::assertNotNull($booking->fresh()?->getRawOriginal('timeout_review_required_at_utc'));
        self::assertSame(1, (int) $departure->fresh()->seats_committed);
        $this->artisan('bablys:bookings:flag-overdue', ['--limit' => '0'])->assertExitCode(1);
    }

    public function test_confirmed_booking_is_not_flagged_by_pending_worker(): void
    {
        [$tour, $departure] = $this->readyTour('c-confirmed-skip');
        $actor = $this->staff('administrator');
        $booking = $this->bookingLifecycle3c()->createInternal($actor, $tour, $departure, 'BOOK-3C-confirmed-sk', 'standard-bdt', 1);
        $this->bookingLifecycle3c()->confirm($actor, $booking);
        $this->setBookingDue3c($booking);
        self::assertSame(0, $this->bookingTimeout3c()->dueCount());
        self::assertSame(0, $this->bookingTimeout3c()->flagOverdueBatch()['flagged']);
        self::assertSame('confirmed', $booking->fresh()?->status);
    }
}
