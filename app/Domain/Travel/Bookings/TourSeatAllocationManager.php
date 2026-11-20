<?php

declare(strict_types=1);

namespace App\Domain\Travel\Bookings;

use App\Domain\Cms\Audit\CmsMutationAudit;
use App\Models\Travel\Tour;
use App\Models\Travel\TourDeparture;
use App\Models\Travel\TourSeatAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * P3E.5A trusted staff-only domain API. NO HTTP routes or customer checkout.
 * All seat mutations follow the P3E.4A parent-tour -> departure locking order.
 * The DB update itself has a capacity predicate for defense in depth.
 * A unique idempotency key remains reserved after release.
 *
 * Future public booking orchestration MUST introduce its own auth, order/payment
 * contract and invoke a separately reviewed trusted adapter, never expose this
 * method as a customer endpoint.
 */
final class TourSeatAllocationManager
{
    public function __construct(
        private readonly TourBookingReadiness $readiness,
        private readonly CmsMutationAudit $audit,
    ) {}

    public function commit(
        User $actor,
        Tour $tour,
        TourDeparture $departure,
        string $referenceKey,
        string $variantCode,
        int $adults,
        int $children = 0,
    ): TourSeatAllocation {
        $this->assertReferenceKey($referenceKey);

        return DB::transaction(function () use (
            $actor, $tour, $departure, $referenceKey, $variantCode, $adults, $children
        ): TourSeatAllocation {
            $this->authorize($actor);
            $lockedTour = Tour::query()->whereKey($tour->getKey())->lockForUpdate()->firstOrFail();
            // Look for a previously used global reference BEFORE readiness checks:
            // identical retries must not allocate twice, even after the cutoff.
            $prior = TourSeatAllocation::query()->where('reference_key', $referenceKey)
                ->lockForUpdate()->first();
            if ($prior !== null) {
                if ((int) $prior->tour_id !== (int) $lockedTour->getKey() ||
                    (int) $prior->departure_id !== (int) $departure->getKey() ||
                    (string) $prior->variant_code !== $variantCode ||
                    (int) $prior->adults !== $adults || (int) $prior->children !== $children ||
                    (string) $prior->status !== 'committed') {
                    $this->reject('reference_key', 'This booking reference was already used for a different or released allocation.');
                }
                return $prior;
            }
            $lockedDeparture = TourDeparture::query()->whereKey($departure->getKey())
                ->lockForUpdate()->firstOrFail();
            if ((int) $lockedDeparture->tour_id !== (int) $lockedTour->getKey()) {
                $this->reject('departure', 'Departure does not belong to this tour.');
            }
            // Never accept a caller-supplied clock or quote amount.
            $ready = $this->readiness->inspect(
                $lockedTour, $lockedDeparture, $variantCode, $adults, $children, now('UTC')
            );
            if (! $ready['ready_for_internal_allocation']) {
                $this->reject('departure', 'Booking prerequisites not met: '.implode(', ', $ready['reasons']));
            }
            $seats = $adults + $children;
            $availableBefore = (int) $lockedDeparture->capacity_total - $seats;
            // An atomic guarded UPDATE prevents overselling even if another DB
            // connection ignores or weakens row locks.
            $changed = DB::table('travel_tour_departures')
                ->where('id', (int) $lockedDeparture->getKey())
                ->where('seats_committed', '<=', $availableBefore)
                ->increment('seats_committed', $seats);
            if ($changed !== 1) {
                $this->reject('departure', 'Insufficient remaining seats.');
            }
            $allocation = TourSeatAllocation::query()->create([
                'tour_id' => (int) $lockedTour->getKey(),
                'departure_id' => (int) $lockedDeparture->getKey(),
                'departure_code' => (string) $lockedDeparture->code,
                'reference_key' => $referenceKey,
                'variant_code' => $variantCode,
                'adults' => $adults,
                'children' => $children,
                'seats' => $seats,
                'status' => 'committed',
                'committed_at' => now('UTC')->format('Y-m-d H:i:s'),
            ]);
            $this->audit->record('tour.seats.committed', $actor, 'tour', $lockedTour, [
                'allocation_id' => (int) $allocation->getKey(),
            ]);
            return $allocation;
        }, 3);
    }

    /**
     * Returns true if seats were released now; false for an identical retry.
     * Released rows are retained to prevent reference reuse or double release.
     */
    public function release(User $actor, Tour $tour, string $referenceKey): bool
    {
        $this->assertReferenceKey($referenceKey);

        return DB::transaction(function () use ($actor, $tour, $referenceKey): bool {
            $this->authorize($actor);
            $lockedTour = Tour::query()->whereKey($tour->getKey())->lockForUpdate()->firstOrFail();
            $allocation = TourSeatAllocation::query()
                ->where('reference_key', $referenceKey)
                ->where('tour_id', (int) $lockedTour->getKey())
                ->lockForUpdate()->first();
            if ($allocation === null) {
                $this->reject('reference_key', 'Unknown allocation reference for this tour.');
            }
            if ((string) $allocation->status === 'released') {
                return false;
            }
            if ((string) $allocation->status !== 'committed' || $allocation->departure_id === null) {
                throw new LogicException('Allocation ledger is inconsistent.');
            }
            $departure = TourDeparture::query()->whereKey((int) $allocation->departure_id)
                ->lockForUpdate()->firstOrFail();
            if ((int) $departure->tour_id !== (int) $lockedTour->getKey()) {
                throw new LogicException('Allocation references a departure from another tour.');
            }
            $changed = DB::table('travel_tour_departures')
                ->where('id', (int) $departure->getKey())
                ->where('seats_committed', '>=', (int) $allocation->seats)
                ->decrement('seats_committed', (int) $allocation->seats);
            if ($changed !== 1) {
                throw new LogicException('Seat counter underflow prevented; check ledger consistency.');
            }
            $allocation->status = 'released';
            $allocation->released_at = now('UTC')->format('Y-m-d H:i:s');
            $allocation->save();
            $this->audit->record('tour.seats.released', $actor, 'tour', $lockedTour, [
                'allocation_id' => (int) $allocation->getKey(),
            ]);
            return true;
        }, 3);
    }

    private function authorize(User $actor): void
    {
        abort_unless(
            Gate::forUser($actor)->allows('tours.edit') && Gate::forUser($actor)->allows('tours.publish'),
            403
        );
    }

    private function assertReferenceKey(string $referenceKey): void
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
