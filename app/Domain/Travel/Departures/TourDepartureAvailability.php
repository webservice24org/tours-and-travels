<?php

declare(strict_types=1);

namespace App\Domain\Travel\Departures;

use App\Models\Travel\TourDeparture;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Staff-only inventory snapshot. Not a public booking guarantee.
 * P3E.5 must add an atomic booking allocation/release service and its own
 * bookability rules; neither a status of open nor seats remaining imply booking.
 *
 * @phpstan-type InventorySnapshot array{remaining_capacity:int,capacity_state:string,public_bookable:bool}
 */
final class TourDepartureAvailability
{
    /** @return InventorySnapshot */
    public function snapshot(TourDeparture $departure, DateTimeInterface $at): array
    {
        $total = (int) $departure->capacity_total;
        $committed = (int) $departure->seats_committed;
        $remaining = max(0, $total - $committed);
        $now = $at->getTimestamp();
        $starts = $this->timestamp($departure->starts_at);
        $cutoff = $departure->booking_closes_at === null ? null : $this->timestamp($departure->booking_closes_at);
        $state = match (true) {
            $departure->status === 'cancelled' => 'cancelled',
            $departure->status === 'draft' => 'draft',
            $departure->status === 'closed' => 'closed',
            $now >= $starts => 'departed',
            $cutoff !== null && $now >= $cutoff => 'cutoff',
            $remaining === 0 => 'full',
            default => 'capacity_remaining',
        };

        return [
            'remaining_capacity' => $remaining,
            'capacity_state' => $state,
            'public_bookable' => false,
        ];
    }

    private function timestamp(string $dbUtc): int
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dbUtc, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new \LogicException('Invalid stored UTC departure timestamp.');
        }
        return $date->getTimestamp();
    }
}
