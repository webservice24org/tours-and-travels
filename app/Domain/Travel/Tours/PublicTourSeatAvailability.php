<?php

declare(strict_types=1);

namespace App\Domain\Travel\Tours;

use App\Models\Travel\TourDeparture;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * P3H.3. Read-only, allowlisted public departure seat snapshots.
 *
 * Departure.seats_committed is authoritative for *availability* because it
 * includes pending bookings and possibly other active allocations. The
 * confirmed/pending breakdown is derived only from non-cancelled bookings.
 * Never expose allocation IDs, booking references, people or staff notes.
 */
final class PublicTourSeatAvailability
{
    /**
     * @param Collection<int, TourDeparture> $departures The caller must scope these
     *     to one publicly published tour and its public-eligible departures.
     * @return array<int, array{total_seats:int, confirmed_seats:int, confirmed_bookings:int, pending_seats:int, pending_bookings:int, other_committed_seats:int, available_seats:int}>
     */
    public function forDepartures(Collection $departures): array
    {
        if ($departures->isEmpty()) {
            return [];
        }

        $ids = $departures->map(static fn (TourDeparture $departure): int => (int) $departure->getKey())->all();
        $totals = [];
        foreach (DB::table('travel_tour_bookings')
            ->select('departure_id', 'status')
            ->selectRaw('SUM(seats) AS booked_seats, COUNT(*) AS booking_count')
            ->whereIn('departure_id', $ids)
            ->whereIn('status', ['confirmed', 'pending_confirmation'])
            ->groupBy('departure_id', 'status')
            ->get() as $row) {
            $id = (int) $row->departure_id;
            $status = (string) $row->status;
            $totals[$id][$status] = [
                'seats' => max(0, (int) $row->booked_seats),
                'bookings' => max(0, (int) $row->booking_count),
            ];
        }

        $result = [];
        foreach ($departures as $departure) {
            $id = (int) $departure->getKey();
            $capacity = max(0, (int) $departure->capacity_total);
            // Clamp display-only anomalies. Domain allocation service remains authoritative.
            $committed = min($capacity, max(0, (int) $departure->seats_committed));
            $confirmed = $totals[$id]['confirmed']['seats'] ?? 0;
            $pending = $totals[$id]['pending_confirmation']['seats'] ?? 0;
            $result[$id] = [
                'total_seats' => $capacity,
                'confirmed_seats' => $confirmed,
                'confirmed_bookings' => $totals[$id]['confirmed']['bookings'] ?? 0,
                'pending_seats' => $pending,
                'pending_bookings' => $totals[$id]['pending_confirmation']['bookings'] ?? 0,
                'other_committed_seats' => max(0, $committed - $confirmed - $pending),
                'available_seats' => max(0, $capacity - $committed),
            ];
        }

        return $result;
    }
}
