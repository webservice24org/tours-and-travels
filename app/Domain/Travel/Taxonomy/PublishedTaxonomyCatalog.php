<?php

declare(strict_types=1);

namespace App\Domain\Travel\Taxonomy;

use App\Models\Travel\Destination;
use App\Models\Travel\TourCategory;

/**
 * Server-approved builder data source. Never exposes unpublished taxonomy rows.
 * No public route or builder block is enabled by this P3A package.
 */
final class PublishedTaxonomyCatalog
{
    /**
     * @return list<array{id:int, name:string, slug:string, summary:?string}>
     */
    public function topLevelCategories(int $limit = 12): array
    {
        $categories = TourCategory::query()
            ->published()
            ->whereNull('parent_id')
            ->ordered()
            ->limit($this->boundLimit($limit))
            ->get(['id', 'name', 'slug', 'summary']);

        return array_values($categories->map(static fn (TourCategory $category): array => [
            'id' => (int) $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'summary' => $category->summary,
        ])->all());
    }

    /**
     * @return list<array{id:int, name:string, slug:string, country_code:string, summary:?string}>
     */
    public function destinations(?string $countryCode = null, int $limit = 24): array
    {
        $query = Destination::query()->published();

        if ($countryCode !== null) {
            $query->where('country_code', strtoupper($countryCode));
        }

        $destinations = $query->ordered()->limit($this->boundLimit($limit))
            ->get(['id', 'name', 'slug', 'country_code', 'summary']);

        return array_values($destinations->map(static fn (Destination $destination): array => [
            'id' => (int) $destination->id,
            'name' => $destination->name,
            'slug' => $destination->slug,
            'country_code' => $destination->country_code,
            'summary' => $destination->summary,
        ])->all());
    }

    private function boundLimit(int $limit): int
    {
        return max(1, min(50, $limit));
    }
}
