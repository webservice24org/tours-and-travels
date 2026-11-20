<?php

declare(strict_types=1);

namespace App\Domain\Travel\Tours;

use App\Models\Travel\Tour;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Called while TourManager holds the parent-tour row lock. ItineraryManager
 * locks the same row before writes, serializing itinerary and duration edits.
 */
final class TourDurationGuard
{
    public function assertCanResize(Tour $tour, int $proposedDays): void
    {
        if ($proposedDays >= (int) $tour->duration_days) {
            return;
        }

        $lastSavedDay = (int) (DB::table('travel_tour_itinerary_days')
            ->where('tour_id', $tour->getKey())
            ->max('day_number') ?? 0);

        if ($lastSavedDay > $proposedDays) {
            throw ValidationException::withMessages([
                'duration_days' => 'This tour has a saved Day '.$lastSavedDay.'. Remove trailing itinerary days before reducing the tour duration.',
            ]);
        }
    }
}
