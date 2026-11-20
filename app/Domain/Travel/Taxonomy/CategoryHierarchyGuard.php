<?php

declare(strict_types=1);

namespace App\Domain\Travel\Taxonomy;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Models\Travel\TourCategory;
use Illuminate\Validation\ValidationException;

/**
 * Use within a transaction when implementing category create/reparent/delete.
 * Reparent operations must lock relevant rows in P3B to prevent concurrency races.
 */
final class CategoryHierarchyGuard
{
    public function assertValidParent(?int $categoryId, ?int $proposedParentId): void
    {
        if ($proposedParentId === null) {
            return;
        }

        $visited = [];
        $currentId = $proposedParentId;

        for ($depth = 0; $currentId !== null; $depth++) {
            if ($depth >= 30 || isset($visited[$currentId])) {
                throw ValidationException::withMessages([
                    'parent_id' => 'The category tree is invalid or too deep.',
                ]);
            }

            if ($categoryId !== null && $currentId === $categoryId) {
                throw ValidationException::withMessages([
                    'parent_id' => 'A category cannot be its own parent or descendant.',
                ]);
            }

            $visited[$currentId] = true;
            $parent = TourCategory::query()->find($currentId);

            if (! $parent instanceof TourCategory) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Choose an existing, non-deleted parent category.',
                ]);
            }

            if ($depth === 0 && $parent->status === PublicationStatus::Archived) {
                throw ValidationException::withMessages([
                    'parent_id' => 'An archived category cannot receive new child categories.',
                ]);
            }

            $currentId = $parent->parent_id;
        }
    }

    public function assertCanSoftDelete(TourCategory $category): void
    {
        if ($category->children()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Reassign or remove child categories before deleting this category.',
            ]);
        }
    }
}
