<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Travel;

use App\Domain\Travel\Itineraries\TourItineraryManager;
use App\Http\Controllers\Controller;
use App\Models\Travel\Tour;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Staff-only itinerary JSON API. Domain manager owns the true business rules. */
final class TourItineraryController extends Controller
{
    public function __construct(private readonly TourItineraryManager $itineraries) {}

    public function show(Request $request, Tour $tour): JsonResponse
    {
        $days = $this->itineraries->forStaff($this->actor($request), $tour);

        return response()->json([
            'data' => $days,
            'meta' => [
                'duration_days' => (int) $tour->duration_days,
                'status' => $tour->status->value,
            ],
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function replace(Request $request, Tour $tour): JsonResponse
    {
        // Empty lists are valid for drafts; 'required' would reject [].
        $validated = $request->validate([
            'days' => ['present', 'array', 'list', 'max:365'],
        ]);

        /** @var array<array-key, mixed> $days */
        $days = $validated['days'];
        $actor = $this->actor($request);
        $this->itineraries->replace($actor, $tour, $days);

        $fresh = Tour::query()->whereKey($tour->getKey())->firstOrFail();

        return response()->json([
            'data' => $this->itineraries->forStaff($actor, $fresh),
            'meta' => [
                'duration_days' => (int) $fresh->duration_days,
                'status' => $fresh->status->value,
            ],
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
