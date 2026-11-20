<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Travel;

use App\Domain\Travel\Tours\TourMediaService;
use App\Http\Controllers\Controller;
use App\Models\Travel\Tour;
use App\Models\Travel\TourMedia;
use App\Models\Travel\TourTag;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** Isolated tour tag suggestions and tour-scoped image CRUD. */
final class TourEditorialController extends Controller
{
    public function __construct(private readonly TourMediaService $mediaService) {}

    public function suggestions(Request $request): JsonResponse
    {
        Gate::authorize('tours.view');
        $query = $request->validate(['q' => ['sometimes', 'string', 'max:80']]);
        $term = mb_strtolower(trim((string) ($query['q'] ?? '')));
        $builder = TourTag::query();
        if ($term !== '') {
            $escaped = addcslashes($term, '%_\\');
            $builder->where('name_key', 'like', '%'.$escaped.'%');
        }
        $tags = $builder->orderBy('name_key')->limit(15)->get(['id', 'name']);
        return response()->json(['data' => $tags->map(static fn (TourTag $tag): array => [
            'id' => (int) $tag->getKey(), 'name' => $tag->name,
        ])->values()->all()])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function index(Tour $tour): JsonResponse
    {
        Gate::authorize('tours.view');
        $items = TourMedia::query()->where('tour_id', $tour->getKey())->orderBy('sort_order')->orderBy('id')->get();
        return response()->json(['data' => $items->map(fn (TourMedia $image): array => $this->fields($image))->all()])
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function store(Request $request, Tour $tour): JsonResponse
    {
        $this->authorizeEdit($tour);
        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4000,max_height=4000'],
            'featured' => ['sometimes', 'boolean'],
        ]);
        $file = $request->file('image');
        abort_unless($file instanceof \Illuminate\Http\UploadedFile, 422);
        $image = $this->mediaService->upload($tour, $this->actor($request), $file, (bool) ($data['featured'] ?? false));
        return response()->json(['data' => $this->fields($image)], 201);
    }

    public function update(Request $request, Tour $tour, TourMedia $media): JsonResponse
    {
        $this->authorizeEdit($tour);
        $data = $request->validate([
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:200'],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
        $updated = $this->mediaService->update($tour, $media, $this->actor($request), $data);
        return response()->json(['data' => $this->fields($updated)]);
    }

    public function destroy(Request $request, Tour $tour, TourMedia $media): Response
    {
        $this->authorizeEdit($tour);
        $this->mediaService->delete($tour, $media, $this->actor($request));
        return response()->noContent();
    }

    private function authorizeEdit(Tour $tour): void
    {
        Gate::authorize('tours.edit');
        if (in_array($tour->status->value, ['published', 'scheduled'], true)) {
            Gate::authorize('tours.publish');
        }
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        return $actor;
    }

    /** @return array<string, mixed> */
    private function fields(TourMedia $media): array
    {
        return [
            'id' => (int) $media->getKey(), 'tour_id' => (int) $media->tour_id,
            'url' => '/storage/'.$media->path, 'path' => $media->path,
            'alt_text' => $media->alt_text, 'sort_order' => (int) $media->sort_order,
            'is_featured' => (bool) $media->is_featured,
            'width' => (int) $media->width, 'height' => (int) $media->height,
        ];
    }
}
