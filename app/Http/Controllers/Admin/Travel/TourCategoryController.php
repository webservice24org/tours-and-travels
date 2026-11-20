<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Travel;

use App\Domain\Travel\Taxonomy\TourCategoryManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Travel\StoreTourCategoryRequest;
use App\Http\Requests\Admin\Travel\UpdateTourCategoryRequest;
use App\Models\Travel\TourCategory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** JSON backend only; React TSX screens are delivered in P3B.3. */
final class TourCategoryController extends Controller
{
    public function __construct(private readonly TourCategoryManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('taxonomy.manage');
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['all', 'draft', 'in_review', 'scheduled', 'published', 'archived'])],
            'root_only' => ['sometimes', 'boolean'],
            'parent_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $query = TourCategory::query()->withCount('children');
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(static function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('slug', 'like', '%'.$search.'%');
            });
        }
        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['root_only']) && filter_var($filters['root_only'], FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNull('parent_id');
        } elseif (isset($filters['parent_id'])) {
            $query->where('parent_id', (int) $filters['parent_id']);
        }

        $page = $query->orderBy('sort_order')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn (TourCategory $category): array => $this->fields($category))->values()->all(),
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
        $items = TourCategory::query()->where('status', '!=', 'archived')
            ->orderBy('sort_order')->orderBy('id')->limit(200)
            ->get(['id', 'name', 'parent_id', 'status']);

        return response()->json(['data' => $items->map(static fn (TourCategory $category): array => [
            'id' => (int) $category->id,
            'name' => $category->name,
            'parent_id' => $category->parent_id,
            'status' => $category->status->value,
        ])->values()->all()]);
    }

    public function show(TourCategory $category): JsonResponse
    {
        Gate::authorize('taxonomy.manage');
        $category->loadCount('children');

        return response()->json(['data' => $this->fields($category)]);
    }

    public function store(StoreTourCategoryRequest $request): JsonResponse
    {
        $category = $this->manager->create($this->actor($request), $request->validated());
        $category->loadCount('children');

        return response()->json(['data' => $this->fields($category)], 201);
    }

    public function update(UpdateTourCategoryRequest $request, TourCategory $category): JsonResponse
    {
        $category = $this->manager->update($this->actor($request), $category, $request->validated());
        $category->loadCount('children');

        return response()->json(['data' => $this->fields($category)]);
    }

    public function destroy(Request $request, TourCategory $category): Response
    {
        Gate::authorize('taxonomy.manage');
        $this->manager->softDelete($this->actor($request), $category);

        return response()->noContent();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    /** @return array{id:int, name:string, slug:string, parent_id:?int, summary:?string, description:?string, status:string, sort_order:int, children_count:int} */
    private function fields(TourCategory $category): array
    {
        return [
            'id' => (int) $category->getKey(),
            'name' => (string) $category->name,
            'slug' => (string) $category->slug,
            'parent_id' => $category->parent_id,
            'summary' => $category->summary,
            'description' => $category->getAttribute('description') === null ? null : (string) $category->getAttribute('description'),
            'status' => $category->status->value,
            'sort_order' => (int) $category->sort_order,
            'children_count' => (int) ($category->getAttribute('children_count') ?? 0),
        ];
    }
}
