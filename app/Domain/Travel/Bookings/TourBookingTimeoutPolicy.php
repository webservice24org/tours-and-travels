<?php

declare(strict_types=1);

namespace App\Domain\Travel\Bookings;

use App\Domain\Cms\Audit\CmsMutationAudit;
use App\Models\Travel\Tour;
use App\Models\Travel\TourBooking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * Booking != request: ONLY staff bookings have seat allocations.
 * Overdue booking = human review flag, NEVER automatic cancellation/release.
 * Lock order matches B1: TOUR -> BOOKING; never acquire a tour after booking.
 */
final class TourBookingTimeoutPolicy
{
    public function __construct(private readonly CmsMutationAudit $audit) {}

    /** Called inside the original createInternal() transaction after creating the booking. */
    public function stampNewBookingDeadline(TourBooking $booking): void
    {
        if (! (bool) config('bablys_booking_payment.timeout_enabled', false)) {
            return;
        }
        $hours = (int) config('bablys_booking_payment.pending_confirmation_hours', 48);
        if ($hours < 1 || $hours > 168) {
            throw new LogicException('Bablys booking confirmation hours must be between 1 and 168.');
        }
        if ((string) $booking->status !== 'pending_confirmation') {
            throw new LogicException('Cannot set a pending deadline on a non-pending booking.');
        }
        $booking->setAttribute('confirmation_due_at_utc', now('UTC')->addHours($hours)->format('Y-m-d H:i:s'));
        $booking->save();
    }

    /** Mandatory under booking row lock, including when the feature toggle later changes. */
    public function assertConfirmAllowed(TourBooking $booking): void
    {
        if ($this->overdue($booking, CarbonImmutable::instance(now('UTC')))) {
            $this->reject('status', 'Confirmation deadline passed; authorized staff must review and renew it.');
        }
    }

    /** Stronger guarantee: cancelling a paid booking needs a separate approved refund process. */
    public function assertCancellationAllowed(TourBooking $booking): void
    {
        if ((string) $booking->payment_state !== 'not_collected') {
            $this->reject('payment_state', 'Paid or exceptional bookings require separate refund review before cancellation.');
        }
    }

    /** @return array{selected:int,flagged:int,skipped:int} */
    public function flagOverdueBatch(int $limit = 500): array
    {
        $this->assertLimit($limit);
        $at = CarbonImmutable::instance(now('UTC'));
        $candidates = $this->dueQuery($at)->orderBy('id')->limit($limit)->pluck('id')->all();
        $result = ['selected' => count($candidates), 'flagged' => 0, 'skipped' => 0];
        foreach ($candidates as $candidate) {
            // Read the immutable parent ID before taking any lock; once TOUR is
            // locked, verify booking still belongs to it under BOOKING row lock.
            $bookingId = (int) $candidate;
            $tourId = DB::table('travel_tour_bookings')->where('id', $bookingId)->value('tour_id');
            if ($tourId === null) {
                $result['skipped']++;
                continue;
            }
            $flagged = DB::transaction(function () use ($bookingId, $tourId, $at): bool {
                $tour = Tour::query()->whereKey((int) $tourId)->lockForUpdate()->first();
                if ($tour === null) {
                    return false;
                }
                $booking = TourBooking::query()->whereKey($bookingId)->lockForUpdate()->first();
                if ($booking === null || (int) $booking->tour_id !== (int) $tour->getKey()
                    || (string) $booking->status !== 'pending_confirmation'
                    || (string) $booking->payment_state !== 'not_collected'
                    || $booking->getRawOriginal('timeout_review_required_at_utc') !== null
                    || ! $this->overdue($booking, $at)) {
                    return false;
                }
                $booking->setAttribute('timeout_review_required_at_utc', $at->format('Y-m-d H:i:s'));
                $booking->save();
                // No automatic seat release or booking status transition.
                return true;
            }, 3);
            $result[$flagged ? 'flagged' : 'skipped']++;
        }
        return $result;
    }

    public function dueCount(): int
    {
        return $this->dueQuery(CarbonImmutable::instance(now('UTC')))->count();
    }

    /**
     * Staff decision after external review; renew a pending booking's deadline
     * without changing seat counts, price, approval, or payment status.
     */
    public function renewAfterReview(User $actor, TourBooking $booking, int $extensionMinutes): TourBooking
    {
        if ($extensionMinutes < 15 || $extensionMinutes > 10080) {
            throw new InvalidArgumentException('Review extension must be 15–10080 minutes.');
        }
        return DB::transaction(function () use ($actor, $booking, $extensionMinutes): TourBooking {
            $this->authorizeStaff($actor);
            $tour = Tour::query()->whereKey($booking->tour_id)->lockForUpdate()->firstOrFail();
            $locked = TourBooking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::instance(now('UTC'));
            if ((int) $locked->tour_id !== (int) $tour->getKey()
                || (string) $locked->status !== 'pending_confirmation'
                || (string) $locked->payment_state !== 'not_collected'
                || ! $this->overdue($locked, $now)) {
                $this->reject('status', 'Only overdue, unpaid pending bookings can be renewed.');
            }
            // Only a still-committed seat allocation may be renewed.
            $allocation = $locked->allocation;
            if ($allocation === null || (string) $allocation->status !== 'committed'
                || (int) $allocation->tour_id !== (int) $tour->getKey()
                || (string) $allocation->reference_key !== (string) $locked->reference_key) {
                throw new LogicException('Booking allocation is inconsistent.');
            }
            $departureStarts = $locked->getRawOriginal('departure_starts_at_utc');
            if (! is_string($departureStarts) || $departureStarts <= $now->format('Y-m-d H:i:s')) {
                $this->reject('departure', 'Cannot extend an already-started departure.');
            }
            $actorId = (int) $actor->getKey();
            if ($actorId <= 0) {
                throw new LogicException('Review actor must have a persisted positive ID.');
            }
            $locked->setAttribute('confirmation_due_at_utc', $now->addMinutes($extensionMinutes)->format('Y-m-d H:i:s'));
            $locked->setAttribute('timeout_review_required_at_utc', null);
            $locked->setAttribute('timeout_reviewed_at_utc', $now->format('Y-m-d H:i:s'));
            $locked->setAttribute('timeout_reviewed_by', $actorId);
            $locked->save();
            $this->audit->record('tour.booking.timeout_reviewed', $actor, 'tour', $tour, [
                'booking_id' => (int) $locked->getKey(),
            ]);
            return $locked;
        }, 3);
    }

    private function overdue(TourBooking $booking, CarbonImmutable $cutoff): bool
    {
        $raw = $booking->getRawOriginal('confirmation_due_at_utc');
        return is_string($raw) && $raw <= $cutoff->format('Y-m-d H:i:s');
    }

    private function dueQuery(CarbonImmutable $at): Builder
    {
        return DB::table('travel_tour_bookings')
            ->where('status', 'pending_confirmation')
            ->where('payment_state', 'not_collected')
            ->whereNotNull('confirmation_due_at_utc')
            ->where('confirmation_due_at_utc', '<=', $at->format('Y-m-d H:i:s'))
            ->whereNull('timeout_review_required_at_utc');
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 2000) {
            throw new InvalidArgumentException('Batch size must be between 1 and 2000.');
        }
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
