<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Travel;

use App\Domain\Travel\Taxonomy\DestinationManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Travel\StoreDestinationRequest;
use App\Http\Requests\Admin\Travel\UpdateDestinationRequest;
use App\Models\Travel\Destination;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** JSON-only backend. React TSX screens are delivered in P3B.4. */
final class DestinationController extends Controller
{
    public function __construct(private readonly DestinationManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('taxonomy.manage');
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['all', 'draft', 'in_review', 'published', 'archived'])],
            'country_code' => ['sometimes', 'string', 'size:2', 'regex:/^[a-zA-Z]{2}$/D'],
            'has_coordinates' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $query = Destination::query();
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(static function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('slug', 'like', '%'.$search.'%')
                    ->orWhere('city_name', 'like', '%'.$search.'%')
                    ->orWhere('region_name', 'like', '%'.$search.'%');
            });
        }
        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['country_code'])) {
            $query->where('country_code', strtoupper((string) $filters['country_code']));
        }
        if (isset($filters['has_coordinates'])) {
            if (filter_var($filters['has_coordinates'], FILTER_VALIDATE_BOOLEAN)) {
                $query->whereNotNull('latitude')->whereNotNull('longitude');
            } else {
                $query->whereNull('latitude')->whereNull('longitude');
            }
        }

        $page = $query->orderBy('sort_order')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return response()->json([
            'data' => $page->getCollection()
                ->map(fn (Destination $destination): array => $this->fields($destination))
                ->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function options(): JsonResponse
    {
        Gate::authorize('taxonomy.manage');
        $items = Destination::query()->where('status', '!=', 'archived')
            ->orderBy('sort_order')->orderBy('id')
            ->limit(200)->get(['id', 'name', 'slug', 'country_code', 'status']);

        return response()->json(['data' => $items->map(static fn (Destination $destination): array => [
            'id' => (int) $destination->getKey(),
            'name' => $destination->name,
            'slug' => $destination->slug,
            'country_code' => $destination->country_code,
            'status' => $destination->status->value,
        ])->values()->all()]);
    }

    public function show(Destination $destination): JsonResponse
    {
        Gate::authorize('taxonomy.manage');
        return response()->json(['data' => $this->fields($destination)]);
    }

    public function store(StoreDestinationRequest $request): JsonResponse
    {
        $destination = $this->manager->create($this->actor($request), $request->validated());

        return response()->json(['data' => $this->fields($destination)], 201);
    }

    public function update(UpdateDestinationRequest $request, Destination $destination): JsonResponse
    {
        $destination = $this->manager->update($this->actor($request), $destination, $request->validated());

        return response()->json(['data' => $this->fields($destination)]);
    }

    public function destroy(Request $request, Destination $destination): Response
    {
        Gate::authorize('taxonomy.manage');
        $this->manager->softDelete($this->actor($request), $destination);

        return response()->noContent();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    /**
     * @return array{id:int, name:string, slug:string, country_code:string, region_name:?string, city_name:?string, summary:?string, description:?string, latitude:?string, longitude:?string, status:string, sort_order:int}
     */
    private function fields(Destination $destination): array
    {
        return [
            'id' => (int) $destination->getKey(),
            'name' => $destination->name,
            'slug' => $destination->slug,
            'country_code' => $destination->country_code,
            'region_name' => $destination->region_name,
            'city_name' => $destination->city_name,
            'summary' => $destination->summary,
            'description' => $destination->getAttribute('description') === null
                ? null : (string) $destination->getAttribute('description'),
            'latitude' => $destination->latitude === null ? null : (string) $destination->latitude,
            'longitude' => $destination->longitude === null ? null : (string) $destination->longitude,
            'status' => $destination->status->value,
            'sort_order' => (int) $destination->sort_order,
        ];
    }
}
