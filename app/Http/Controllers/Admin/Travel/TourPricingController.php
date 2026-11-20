<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Travel;

use App\Domain\Travel\Pricing\TourPricingManager;
use App\Domain\Travel\Pricing\TourPricingRules;
use App\Http\Controllers\Controller;
use App\Models\Travel\Tour;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Staff-only pricing API; the domain manager is the authorization and money authority. */
final class TourPricingController extends Controller
{
    public function __construct(private readonly TourPricingManager $pricing)
    {
    }

    public function show(Request $request, Tour $tour): JsonResponse
    {
        $variants = $this->pricing->forStaff($this->actor($request), $tour);

        return $this->privateResponse([
            'data' => $variants,
            'meta' => [
                'currencies' => TourPricingRules::CURRENCIES,
                'status' => $tour->status->value,
            ],
        ]);
    }

    public function replace(Request $request, Tour $tour): JsonResponse
    {
        // Empty list intentionally clears all variants. Domain service normalizes every nested field.
        $validated = $request->validate([
            'variants' => ['present', 'array', 'list', 'max:30'],
        ]);
        /** @var array<array-key, mixed> $variants */
        $variants = $validated['variants'];
        $actor = $this->actor($request);
        $this->pricing->replace($actor, $tour, $variants);


        return $this->privateResponse([
            'data' => $this->pricing->forStaff($actor, $tour),
            'meta' => [
                'currencies' => TourPricingRules::CURRENCIES,
                'status' => $tour->refresh()->status->value,
            ],
        ]);

    }

    public function preview(Request $request, Tour $tour): JsonResponse
    {
        // Preview uses the SAVED server-side variant at server 'now', never unsaved client prices.
        $validated = $request->validate([
            'code' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[a-z][a-z0-9_-]{1,59}$/D'],
            'adults' => ['required', 'integer', 'min:1', 'max:1000'],
            'children' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);
        $quote = $this->pricing->previewForStaff(
            $this->actor($request),
            $tour,
            (string) $validated['code'],
            (int) $validated['adults'],
            (int) $validated['children'],
            now(),
        );

        return $this->privateResponse([
            'data' => $quote,
            'meta' => ['quoted_at' => now()->utc()->toIso8601String(), 'not_a_booking' => true],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function privateResponse(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store, max-age=0');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
