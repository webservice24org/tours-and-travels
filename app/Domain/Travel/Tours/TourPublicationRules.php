<?php

declare(strict_types=1);

namespace App\Domain\Travel\Tours;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Models\Travel\Tour;
use App\Models\Travel\TourCategory;
use Illuminate\Validation\ValidationException;

/**
 * Minimum publication gate for the P3C tour/taxonomy domain.
 * Pricing, departure inventory, and itinerary gates are added in P3E.
 */
final class TourPublicationRules
{
    public function assertReady(Tour $tour): void
    {
        if ($tour->trashed() || trim($tour->title) === '' || $tour->duration_days < 1) {
            throw ValidationException::withMessages([
                'tour' => 'Only an active tour with a title and positive duration can be published.',
            ]);
        }

        $hasPublishedCategoryPath = false;
        foreach ($tour->categories()->get() as $category) {
            if ($this->publishedCategoryPath($category)) {
                $hasPublishedCategoryPath = true;
                break;
            }
        }

        if (! $hasPublishedCategoryPath) {
            throw ValidationException::withMessages([
                'category_ids' => 'A published tour requires a published category with published ancestors.',
            ]);
        }

        if (! $tour->destinations()->where('status', PublicationStatus::Published->value)->exists()) {
            throw ValidationException::withMessages([
                'destination_ids' => 'A published tour requires at least one published destination.',
            ]);
        }
    }

    private function publishedCategoryPath(TourCategory $category): bool
    {
        $seen = [];
        $current = $category;
        for ($depth = 0; $depth < 30; $depth++) {
            $id = (int) $current->getKey();
            if (isset($seen[$id]) || $current->status !== PublicationStatus::Published || $current->trashed()) {
                return false;
            }
            $seen[$id] = true;

            if ($current->parent_id === null) {
                return true;
            }

            $parent = TourCategory::query()->find($current->parent_id);
            if (! $parent instanceof TourCategory) {
                return false;
            }
            $current = $parent;
        }

        return false;
    }
}
