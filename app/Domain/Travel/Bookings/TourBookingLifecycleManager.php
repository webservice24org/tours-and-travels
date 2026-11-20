<?php

declare(strict_types=1);

namespace App\Domain\Travel\Bookings;

use App\Domain\Cms\Audit\CmsMutationAudit;
use App\Models\Travel\Tour;
use App\Models\Travel\TourBooking;
use App\Models\Travel\TourDeparture;
use App\Models\Travel\TourSeatAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * P3E.5B.1: TRUSTED STAFF-ONLY booking aggregate, not customer checkout.
 *
 * Lock ordering is ALWAYS parent tour first, then booking reference. The existing
 * P3E.5A seat service is called inside this transaction and owns the allocation
 * reference + departure locks and counter updates. Never call this from a
 * public route or permit the caller to supply a price, payment or booking status.
 *
 * Cancellation releases seats atomically, but is NOT a monetary refund. There
 * is no payment capture, expiry job, public reservation, or traveler PII here.
 */
final class TourBookingLifecycleManager
{
    public function __construct(
        private readonly TourSeatAllocationManager $seats,
        private readonly TourBookingReadiness $readiness,
        private readonly CmsMutationAudit $audit,
    ) {
    }

    public function createInternal(
        User $actor,
        Tour $tour,
        TourDeparture $departure,
        string $referenceKey,
        string $variantCode,
        int $adults,
        int $children = 0,
    ): TourBooking {
        $this->assertReference($referenceKey);

        return DB::transaction(function () use ($actor, $tour, $departure, $referenceKey, $variantCode, $adults, $children): TourBooking {
            $this->authorize($actor);
            $lockedTour = Tour::query()->whereKey($tour->getKey())->lockForUpdate()->firstOrFail();

            $existing = TourBooking::query()->where('reference_key', $referenceKey)
                ->lockForUpdate()->first();
            if ($existing !== null) {
                if (
                    (int) $existing->tour_id !== (int) $lockedTour->getKey()
                    || (int) $existing->departure_id !== (int) $departure->getKey()
                    || (string) $existing->variant_code !== $variantCode
                    || (int) $existing->adults !== $adults
                    || (int) $existing->children !== $children
                    || (string) $existing->status === 'cancelled'
                ) {
                    $this->reject('reference_key', 'Reference is already used or was cancelled.');
                }
                // Identical retry (including confirmed bookings): never reserve twice.
                return $existing;
            }

            // A seat reference allocated outside this booking aggregate must not
            // be retroactively claimed as a new booking.
            if (TourSeatAllocation::query()->where('reference_key', $referenceKey)->exists()) {
                $this->reject('reference_key', 'Reference was already used for a standalone allocation.');
            }

            $freshDeparture = TourDeparture::query()->whereKey($departure->getKey())->firstOrFail();
            if ((int) $freshDeparture->tour_id !== (int) $lockedTour->getKey()) {
                $this->reject('departure', 'Departure does not belong to the selected tour.');
            }
            $at = now('UTC');
            $check = $this->readiness->inspect(
                $lockedTour,
                $freshDeparture,
                $variantCode,
                $adults,
                $children,
                $at
            );
            if (!$check['ready_for_internal_allocation'] || !is_array($check['quote'])) {
                $this->reject('departure', 'Booking prerequisites are not satisfied.');
            }
            $quote = $check['quote'];
            if (
                !isset(
                $quote['currency'],
                $quote['fraction_digits'],
                $quote['regular_total_minor'],
                $quote['discount_minor'],
                $quote['total_minor'],
                $quote['lines']
            ) || !is_array($quote['lines'])
            ) {
                throw new LogicException('Pricing calculator returned an invalid quote snapshot.');
            }

            // Existing P3E.5A service performs a second readiness check, locks
            // the allocation/departure, and atomically guards capacity.
            $allocation = $this->seats->commit(
                $actor,
                $lockedTour,
                $freshDeparture,
                $referenceKey,
                $variantCode,
                $adults,
                $children
            );
            if (
                (int) $allocation->tour_id !== (int) $lockedTour->getKey()
                || (int) $allocation->departure_id !== (int) $freshDeparture->getKey()
            ) {
                throw new LogicException('Seat allocation does not match the booking.');
            }
            $booking = TourBooking::query()->create([
                'tour_id' => (int) $lockedTour->getKey(),
                'departure_id' => (int) $freshDeparture->getKey(),
                'seat_allocation_id' => (int) $allocation->getKey(),
                'reference_key' => $referenceKey,
                'status' => 'pending_confirmation',
                'payment_state' => 'not_collected',
                'tour_title_snapshot' => (string) $lockedTour->title,
                'departure_code_snapshot' => (string) $freshDeparture->code,
                'departure_starts_at_utc' => (string) $freshDeparture->starts_at,
                'departure_timezone' => (string) $freshDeparture->timezone_name,
                'variant_code' => $variantCode,
                'currency' => (string) $quote['currency'],
                'fraction_digits' => (int) $quote['fraction_digits'],
                'adults' => $adults,
                'children' => $children,
                'seats' => $adults + $children,
                'regular_total_minor' => (int) $quote['regular_total_minor'],
                'discount_minor' => (int) $quote['discount_minor'],
                'total_minor' => (int) $quote['total_minor'],
                'price_lines_snapshot' => $quote['lines'],
                'quoted_at_utc' => $at->format('Y-m-d H:i:s'),
                'created_by' => (int) $actor->getKey(),
            ]);
            // P3E.5B.3C: optional deadline stamped inside the seat+booking transaction.
            app(TourBookingTimeoutPolicy::class)->stampNewBookingDeadline($booking);
            $this->audit->record('tour.booking.created', $actor, 'tour', $lockedTour, [
                'booking_id' => (int) $booking->getKey(),
            ]);
            return $booking;
        }, 3);
    }

    /** Confirm a staff-created booking without implying payment was received. */
    public function confirm(User $actor, TourBooking $booking): TourBooking
    {
        return DB::transaction(function () use ($actor, $booking): TourBooking {
            $this->authorize($actor);
            $lockedTour = Tour::query()->whereKey($booking->tour_id)->lockForUpdate()->firstOrFail();
            $locked = TourBooking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $locked->tour_id !== (int) $lockedTour->getKey()) {
                throw new LogicException('Booking/tour mismatch.');
            }
            if ($locked->status === 'confirmed') {
                return $locked;
            }
            if ($locked->status !== 'pending_confirmation') {
                $this->reject('status', 'Cancelled bookings cannot be confirmed.');
            }
            // Must reject overdue bookings even when the scheduled worker has not run.
            app(TourBookingTimeoutPolicy::class)->assertConfirmAllowed($locked);
            $allocation = $locked->allocation;
            if (
                $allocation === null || (string) $allocation->status !== 'committed'
                || (string) $allocation->reference_key !== (string) $locked->reference_key
                || (int) $allocation->tour_id !== (int) $locked->tour_id
            ) {
                throw new LogicException('Cannot confirm without a committed allocation.');
            }
            $locked->status = 'confirmed';
            $locked->confirmed_at = now('UTC')->format('Y-m-d H:i:s');
            $locked->save();
            $this->audit->record('tour.booking.confirmed', $actor, 'tour', $lockedTour, [
                'booking_id' => (int) $locked->getKey(),
            ]);
            return $locked;
        }, 3);
    }

    /** Cancellation is irreversible; payment refunds must be handled separately. */
    public function cancel(User $actor, TourBooking $booking): TourBooking
    {
        return DB::transaction(function () use ($actor, $booking): TourBooking {
            $this->authorize($actor);
            $lockedTour = Tour::query()->whereKey($booking->tour_id)->lockForUpdate()->firstOrFail();
            $locked = TourBooking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $locked->tour_id !== (int) $lockedTour->getKey()) {
                throw new LogicException('Booking/tour mismatch.');
            }
            if ($locked->status === 'cancelled') {
                return $locked;
            }
            if (!in_array($locked->status, ['pending_confirmation', 'confirmed'], true)) {
                throw new LogicException('Unexpected booking status.');
            }
            // Block paid cancellation until a refund workflow exists.
            app(TourBookingTimeoutPolicy::class)->assertCancellationAllowed($locked);
            $allocation = $locked->allocation;
            if (
                $allocation === null || (string) $allocation->reference_key !== (string) $locked->reference_key
                || (int) $allocation->tour_id !== (int) $lockedTour->getKey()
                || (string) $allocation->status !== 'committed'
            ) {
                throw new LogicException('Booking allocation ledger is inconsistent.');
            }
            // Same transaction and tour lock as the booking status transition.
            // The P3E.5A ledger becomes an immutable released tombstone.
            $this->seats->release($actor, $lockedTour, (string) $locked->reference_key);
            $locked->status = 'cancelled';
            $locked->cancelled_at = now('UTC')->format('Y-m-d H:i:s');
            $actorId = (int) $actor->getKey();

            if ($actorId <= 0) {
                throw new LogicException('Cancellation actor must have a valid positive ID.');
            }

            $locked->cancelled_by = $actorId;
            $locked->save();
            $this->audit->record('tour.booking.cancelled', $actor, 'tour', $lockedTour, [
                'booking_id' => (int) $locked->getKey(),
            ]);
            return $locked;
        }, 3);
    }

    private function authorize(User $actor): void
    {
        abort_unless(
            Gate::forUser($actor)->allows('tours.edit')
            && Gate::forUser($actor)->allows('tours.publish'),
            403
        );
    }

    private function assertReference(string $referenceKey): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,99}$/D', $referenceKey) !== 1) {
            $this->reject('reference_key', 'Provide an opaque reference of 8–100 safe ASCII characters.');
        }
    }

    /** @return never */
    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
