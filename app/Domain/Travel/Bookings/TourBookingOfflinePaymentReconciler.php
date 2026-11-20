<?php

declare(strict_types=1);

namespace App\Domain\Travel\Bookings;

use App\Domain\Cms\Audit\CmsMutationAudit;
use App\Models\Travel\Tour;
use App\Models\Travel\TourBooking;
use App\Models\Travel\TourBookingOfflinePayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Explicit staff-only reconciliation of a FULL payment verified OUTSIDE this app.
 * Not a gateway, charge, capture, invoice, refund or monetary settlement.
 * Disabled by default and deliberately has NO HTTP/CLI endpoint.
 * Lock order TOUR -> BOOKING -> existing reconciliation row.
 */
final class TourBookingOfflinePaymentReconciler
{
    public function __construct(
        private readonly CmsMutationAudit $audit,
        private readonly TourBookingTimeoutPolicy $timeouts,
    ) {}

    public function recordExternallyVerifiedFullPayment(
        User $actor,
        TourBooking $booking,
        int $amountMinor,
        string $currency,
        string $externalEvidenceReference,
    ): TourBookingOfflinePayment {
        if (! (bool) config('bablys_booking_payment.manual_reconciliation_enabled', false)) {
            $this->reject('payment', 'Offline payment reconciliation is disabled pending business approval.');
        }
        $this->authorizeStaff($actor);
        $reference = trim($externalEvidenceReference);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{7,127}$/D', $reference) !== 1) {
            $this->reject('evidence', 'Use a valid unique non-PII receipt/reference identifier (8–128 characters).');
        }
        $appKey = (string) config('app.key');
        if ($appKey === '') {
            throw new LogicException('APP_KEY is required to fingerprint payment evidence.');
        }
        // No plain receipt, banking ID, guest token or card data is persisted.
        $fingerprint = hash_hmac('sha256', 'offline-payment:v1:'.strtolower($reference), $appKey);

        return DB::transaction(function () use ($actor, $booking, $amountMinor, $currency, $fingerprint): TourBookingOfflinePayment {
            $tour = Tour::query()->whereKey($booking->tour_id)->lockForUpdate()->firstOrFail();
            $locked = TourBooking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $locked->tour_id !== (int) $tour->getKey()) {
                throw new LogicException('Booking/tour mismatch.');
            }
            $prior = TourBookingOfflinePayment::query()->where('booking_id', $locked->getKey())
                ->lockForUpdate()->first();
            if ($prior !== null) {
                if ((string) $locked->payment_state === 'paid'
                    && hash_equals((string) $prior->evidence_reference_hash, $fingerprint)
                    && (int) $prior->amount_minor === $amountMinor
                    && (string) $prior->currency === $currency) {
                    return $prior; // Identical retry never changes money or audit again.
                }
                $this->reject('payment', 'This booking was already reconciled with different evidence.');
            }
            if (! in_array((string) $locked->status, ['pending_confirmation', 'confirmed'], true)
                || (string) $locked->payment_state !== 'not_collected') {
                $this->reject('payment', 'Cancelled or already-paid bookings cannot be reconciled again.');
            }
            if ($amountMinor <= 0 || $amountMinor !== (int) $locked->total_minor
                || $currency !== (string) $locked->currency) {
                $this->reject('payment', 'Externally verified amount and currency must equal the immutable full booking total.');
            }
            // Pending bookings must be eligible before any manual financial state update.
            if ((string) $locked->status === 'pending_confirmation') {
                $this->timeouts->assertConfirmAllowed($locked);
            }
            if (TourBookingOfflinePayment::query()->where('evidence_reference_hash', $fingerprint)->exists()) {
                $this->reject('evidence', 'This external evidence was already associated with another booking.');
            }
            $actorId = (int) $actor->getKey();
            if ($actorId <= 0) {
                throw new LogicException('Reconciliation actor must have a persisted positive ID.');
            }
            $evidence = TourBookingOfflinePayment::query()->create([
                'booking_id' => (int) $locked->getKey(),
                'evidence_reference_hash' => $fingerprint,
                'currency' => $currency,
                'amount_minor' => $amountMinor,
                'method' => 'offline_verified',
                'verified_by' => $actorId,
                'verified_at_utc' => now('UTC')->format('Y-m-d H:i:s'),
            ]);
            $locked->payment_state = 'paid';
            $locked->save();
            $this->audit->record('tour.booking.offline_payment_reconciled', $actor, 'tour', $tour, [
                'booking_id' => (int) $locked->getKey(),
            ]);
            return $evidence;
        }, 3);
    }

    private function authorizeStaff(User $actor): void
    {
        abort_unless(Gate::forUser($actor)->allows('tours.edit')
            && Gate::forUser($actor)->allows('tours.publish'), 403);
    }

    /** @return never */
    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
