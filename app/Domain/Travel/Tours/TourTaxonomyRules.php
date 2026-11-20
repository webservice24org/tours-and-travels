<?php

declare(strict_types=1);

namespace App\Domain\Travel\Tours;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Models\Travel\Destination;
use App\Models\Travel\TourCategory;
use Illuminate\Validation\ValidationException;

/**
 * Domain guard for P3D's future tour mutation service. This class does not
 * change pivot data or bypass RBAC; P3D will run checks inside its transaction.
 */
final class TourTaxonomyRules
{
    /**
     * @param list<int> $categoryIds
     * @param list<int> $destinationIds
     */
    public function assertAssignable(array $categoryIds, array $destinationIds): void
    {
        $this->checkIds($categoryIds, 'category_ids');
        $this->checkIds($destinationIds, 'destination_ids');

        if ($categoryIds !== []) {
            $foundCategories = TourCategory::query()
                ->whereKey($categoryIds)
                ->where('status', '!=', PublicationStatus::Archived->value)
                ->count();

            if ($foundCategories !== count($categoryIds)) {
                throw ValidationException::withMessages([
                    'category_ids' => 'All selected categories must exist and not be archived or deleted.',
                ]);
            }
        }

        if ($destinationIds !== []) {
            $foundDestinations = Destination::query()
                ->whereKey($destinationIds)
                ->where('status', '!=', PublicationStatus::Archived->value)
                ->count();

            if ($foundDestinations !== count($destinationIds)) {
                throw ValidationException::withMessages([
                    'destination_ids' => 'All selected destinations must exist and not be archived or deleted.',
                ]);
            }
        }
    }


    /**
     * Validate identifiers received at runtime.
     *
     * @param list<mixed> $ids
     */
    private function checkIds(array $ids, string $field): void
    {
        if (count($ids) > 30 || count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                $field => 'Choose at most 30 unique records.',
            ]);
        }

        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw ValidationException::withMessages([
                    $field => 'Selected identifiers must be positive integers.',
                ]);
            }
        }
    }
}
