<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Travel;

use App\Domain\Travel\Departures\TourDepartureManager;
use App\Http\Controllers\Controller;
use App\Models\Travel\Tour;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Staff-only departure inventory. Never exposes public booking availability. */
final class TourDepartureController extends Controller
{
    public function __construct(private readonly TourDepartureManager $departures) {}

    public function show(Request $request, Tour $tour): JsonResponse
    {
        return $this->respond($this->actor($request), $tour);
    }

    public function replace(Request $request, Tour $tour): JsonResponse
    {
        // Required-but-empty list intentionally clears uncommitted departures.
        $validated = $request->validate([
            'departures' => ['present', 'array', 'list', 'max:200'],
        ]);
        /** @var array<array-key,mixed> $input */
        $input = $validated['departures'];
        $actor = $this->actor($request);
        $this->departures->replace($actor, $tour, $input);

        return $this->respond($actor, $tour);
    }

    private function respond(User $actor, Tour $tour): JsonResponse
    {
        // Explicit server-time snapshot, with no client-controlled seat counters.
        $data = $this->departures->forStaff($actor, $tour, now());
        $fresh = Tour::query()->whereKey($tour->getKey())->firstOrFail();

        return response()->json([
            'data' => $data,
            'meta' => [
                'status' => $fresh->status->value,
                'timezones_example' => ['Asia/Dhaka', 'Asia/Kolkata', 'Asia/Dubai', 'Europe/London', 'America/New_York', 'UTC'],
                'departure_statuses' => ['draft', 'open', 'closed', 'cancelled'],
                'inventory_only' => true,
                'public_bookable' => false,
                'as_of' => now()->utc()->toIso8601String(),
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
