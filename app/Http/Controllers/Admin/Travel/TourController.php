<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Travel;

use App\Domain\Travel\Tours\TourManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Travel\StoreTourRequest;
use App\Http\Requests\Admin\Travel\UpdateTourRequest;
use App\Models\Travel\Destination;
use App\Models\Travel\Tour;
use App\Models\Travel\TourCategory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** P3D.1 secure JSON backend; React tour editor follows in P3D.2. */
final class TourController extends Controller
{
    public function __construct(private readonly TourManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('tours.view');
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['all', 'draft', 'in_review', 'scheduled', 'published', 'archived'])],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'destination_id' => ['sometimes', 'integer', 'min:1'],
            'featured' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $query = Tour::query()->withCount(['categories', 'destinations']);
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(static function ($q) use ($search): void {
                $q->where('title', 'like', '%'.$search.'%')
                    ->orWhere('slug', 'like', '%'.$search.'%');
            });
        }
        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['category_id'])) {
            $categoryId = (int) $filters['category_id'];
            $query->whereHas('categories', static function ($q) use ($categoryId): void {
                $q->whereKey($categoryId);
            });
        }
        if (isset($filters['destination_id'])) {
            $destinationId = (int) $filters['destination_id'];
            $query->whereHas('destinations', static function ($q) use ($destinationId): void {
                $q->whereKey($destinationId);
            });
        }
        if (array_key_exists('featured', $filters)) {
            $query->where('is_featured', filter_var($filters['featured'], FILTER_VALIDATE_BOOLEAN));
        }
        $paginator = $query->ordered()->paginate((int) ($filters['per_page'] ?? 20));

        return response()->json([
            'data' => $paginator->getCollection()->map(
                fn (Tour $tour): array => $this->fields($tour, false)
            )->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function taxonomyOptions(): JsonResponse
    {
        Gate::authorize('tours.view');
        $categories = TourCategory::query()->where('status', '!=', 'archived')
            ->ordered()->limit(200)->get(['id', 'name', 'slug', 'status']);
        $destinations = Destination::query()->where('status', '!=', 'archived')
            ->ordered()->limit(200)->get(['id', 'name', 'slug', 'country_code', 'status']);

        return response()->json([
            'categories' => $categories->map(static fn (TourCategory $category): array => [
                'id' => (int) $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'status' => $category->status->value,
            ])->values()->all(),
            'destinations' => $destinations->map(static fn (Destination $destination): array => [
                'id' => (int) $destination->id,
                'name' => $destination->name,
                'slug' => $destination->slug,
                'country_code' => $destination->country_code,
                'status' => $destination->status->value,
            ])->values()->all(),
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function show(Tour $tour): JsonResponse
    {
        Gate::authorize('tours.view');
        $tour->load(['categories', 'destinations', 'tags'])->loadCount(['categories', 'destinations']);
        return response()->json(['data' => $this->fields($tour, true)])
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function store(StoreTourRequest $request): JsonResponse
    {
        $tour = $this->manager->create($this->actor($request), $request->validated());
        return response()->json(['data' => $this->fields($tour, true)], 201);
    }

    public function update(UpdateTourRequest $request, Tour $tour): JsonResponse
    {
        $updated = $this->manager->update($this->actor($request), $tour, $request->validated());
        return response()->json(['data' => $this->fields($updated, true)]);
    }

    public function destroy(Request $request, Tour $tour): Response
    {
        Gate::authorize('tours.edit');
        $this->manager->softDelete($this->actor($request), $tour);
        return response()->noContent();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        return $actor;
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(Tour $tour, bool $includeBody): array
    {
        $data = [
            'id' => (int) $tour->getKey(),
            'title' => $tour->title,
            'slug' => $tour->slug,
            'summary' => $tour->summary,
            'status' => $tour->status->value,
            'duration_days' => (int) $tour->duration_days,
            'duration_nights' => $tour->duration_nights,
            'is_featured' => (bool) $tour->is_featured,
            'sort_order' => (int) $tour->sort_order,
            'scheduled_for' => $tour->scheduled_for?->toIso8601String(),
            'published_at' => $tour->published_at?->toIso8601String(),
            'category_count' => (int) ($tour->getAttribute('categories_count') ?? $tour->categories()->count()),
            'destination_count' => (int) ($tour->getAttribute('destinations_count') ?? $tour->destinations()->count()),
        ];
        if ($includeBody) {
            $data['description'] = $tour->getAttribute('description');
            $data['category_ids'] = $tour->categories->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
            $data['destination_ids'] = $tour->destinations->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
            $data['tag_names'] = $tour->tags()->orderByPivot('sort_order')->pluck('travel_tour_tags.name')->values()->all();
        }
        return $data;
    }
}
