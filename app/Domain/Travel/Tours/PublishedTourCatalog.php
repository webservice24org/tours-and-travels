<?php

declare(strict_types=1);

namespace App\Domain\Travel\Tours;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Models\Travel\Tour;
use Illuminate\Database\Eloquent\Builder;

/**
 * Future builder data source. No route or visual block is registered in P3C.
 * This service excludes draft, archived, scheduled and future publications.
 * It deliberately exposes no price or inventory until P3E.
 */
final class PublishedTourCatalog
{
    /**
     * @return list<array{id:int,title:string,slug:string,summary:?string,duration_days:int,is_featured:bool}>
     */
    public function tours(
        ?string $categorySlug = null,
        ?string $destinationSlug = null,
        int $limit = 12,
        bool $featuredOnly = false,
    ): array {
        $query = Tour::query()->publiclyAvailable();

        if ($categorySlug !== null) {
            $query->whereHas('categories', static function (Builder $categories) use ($categorySlug): void {
                $categories->where('slug', $categorySlug)
                    ->where('status', PublicationStatus::Published->value);
            });
        }

        if ($destinationSlug !== null) {
            $query->whereHas('destinations', static function (Builder $destinations) use ($destinationSlug): void {
                $destinations->where('slug', $destinationSlug)
                    ->where('status', PublicationStatus::Published->value);
            });
        }

        if ($featuredOnly) {
            $query->where('is_featured', true);
        }

        $rows = $query->ordered()->limit(max(1, min(50, $limit)))
            ->get(['id', 'title', 'slug', 'summary', 'duration_days', 'is_featured']);

        return array_values($rows->map(static fn (Tour $tour): array => [
            'id' => (int) $tour->id,
            'title' => $tour->title,
            'slug' => $tour->slug,
            'summary' => $tour->summary,
            'duration_days' => $tour->duration_days,
            'is_featured' => $tour->is_featured,
        ])->all());
    }
}
