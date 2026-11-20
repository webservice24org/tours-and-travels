<?php

declare(strict_types=1);

namespace App\Domain\Travel\Taxonomy;

use App\Domain\Cms\Audit\CmsMutationAudit;
use App\Domain\Cms\Enums\PublicationStatus;
use App\Models\Travel\TourCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * P3B.1: writes the P3A taxonomy without changing public catalogue contracts.
 * All writes, hierarchy guards, and audit records share one DB transaction.
 */
final class TourCategoryManager
{
    public function __construct(
        private readonly CategoryHierarchyGuard $hierarchy,
        private readonly TaxonomySlug $slugs,
        private readonly CmsMutationAudit $audit,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function create(User $actor, array $input): TourCategory
    {
        return DB::transaction(function () use ($actor, $input): TourCategory {
            $this->authorizeActor($actor);
            $this->lockTaxonomyTree();

            $slug = $this->slugs->normalize((string) $input['slug']);
            $this->assertSlugFree($slug);
            $parentId = $this->optionalId($input['parent_id'] ?? null);
            $status = (string) ($input['status'] ?? PublicationStatus::Draft->value);

            $this->hierarchy->assertValidParent(null, $parentId);
            $this->assertPublishablePath($status, $parentId);

            $category = TourCategory::query()->create([
                'name' => trim((string) $input['name']),
                'slug' => $slug,
                'parent_id' => $parentId,
                'summary' => $input['summary'] ?? null,
                'description' => $input['description'] ?? null,
                'status' => $status,
                'sort_order' => (int) ($input['sort_order'] ?? 0),
            ]);

            $this->audit->record('taxonomy.category.created', $actor, 'tour_category', $category, [
                'slug' => $slug,
                'status' => $status,
                'parent_id' => $parentId,
            ]);

            return $category->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function update(User $actor, TourCategory $category, array $input): TourCategory
    {
        return DB::transaction(function () use ($actor, $category, $input): TourCategory {
            $this->authorizeActor($actor);
            $this->lockTaxonomyTree();
            $locked = $this->getLockedCategory($category);

            if (
                array_key_exists('slug', $input) &&
                $this->slugs->normalize((string) $input['slug']) !== $locked->slug
            ) {
                throw ValidationException::withMessages([
                    'slug' => 'Category slugs cannot change until URL redirects are supported.',
                ]);
            }

            $oldStatus = $locked->status->value;
            $oldParent = $locked->parent_id;
            $parentId = array_key_exists('parent_id', $input)
                ? $this->optionalId($input['parent_id']) : $oldParent;
            $status = (string) ($input['status'] ?? $oldStatus);

            if ($parentId !== $oldParent) {
                $this->hierarchy->assertValidParent((int) $locked->getKey(), $parentId);
            }
            $this->assertPublishablePath($status, $parentId);
            if (
                $oldStatus === PublicationStatus::Published->value &&
                $status !== PublicationStatus::Published->value &&
                $locked->children()->where('status', PublicationStatus::Published->value)->exists()
            ) {
                throw ValidationException::withMessages([
                    'status' => 'Unpublish published child categories before unpublishing their parent.',
                ]);
            }

            $updates = [];
            foreach (['name', 'summary', 'description', 'status', 'sort_order'] as $field) {
                if (array_key_exists($field, $input)) {
                    $updates[$field] = $field === 'name' ? trim((string) $input[$field]) : $input[$field];
                }
            }
            if (array_key_exists('parent_id', $input)) {
                $updates['parent_id'] = $parentId;
            }

            $locked->fill($updates);
            if ($locked->isDirty()) {

                /** @var list<string> $fields */
                $fields = array_keys($locked->getDirty());
                sort($fields, SORT_STRING);

                $locked->save();
                // Content bodies / names are intentionally excluded from audit metadata.
                $this->audit->record('taxonomy.category.updated', $actor, 'tour_category', $locked, [
                    'changed_fields' => $fields,
                    'before' => ['status' => $oldStatus, 'parent_id' => $oldParent],
                    'after' => ['status' => $status, 'parent_id' => $parentId],
                ]);
            }

            return $locked->refresh();
        }, 3);
    }

    public function softDelete(User $actor, TourCategory $category): void
    {
        DB::transaction(function () use ($actor, $category): void {
            $this->authorizeActor($actor);
            $this->lockTaxonomyTree();
            $locked = $this->getLockedCategory($category);
            $this->hierarchy->assertCanSoftDelete($locked);
            $oldStatus = $locked->status->value;
            $parentId = $locked->parent_id;
            $slug = $locked->slug;

            $locked->delete();
            $this->audit->record('taxonomy.category.deleted', $actor, 'tour_category', $locked, [
                'slug' => $slug,
                'previous_status' => $oldStatus,
                'parent_id' => $parentId,
            ]);
        }, 3);
    }

    private function authorizeActor(User $actor): void
    {
        // Never trust a controller-only check: re-evaluate current DB-backed grants.
        abort_unless(Gate::forUser($actor)->allows('taxonomy.manage'), 403);
    }

    private function lockTaxonomyTree(): void
    {
        // P3B.1's conservative tree-wide lock prevents concurrent reparent cycles.
        // Revisit lock granularity when taxonomy volume materially increases.
        TourCategory::query()->orderBy('id')->lockForUpdate()->get(['id']);
    }

    private function getLockedCategory(TourCategory $category): TourCategory
    {
        $locked = TourCategory::query()->whereKey($category->getKey())->lockForUpdate()->first();
        if (!$locked instanceof TourCategory) {
            abort(404);
        }

        return $locked;
    }

    private function assertSlugFree(string $slug): void
    {
        // Keep slugs reserved across soft deletion to prevent breaking old links.
        if (TourCategory::withTrashed()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => 'This category slug is already in use.']);
        }
    }

    private function assertPublishablePath(string $status, ?int $parentId): void
    {
        if ($status !== PublicationStatus::Published->value || $parentId === null) {
            return;
        }

        $visited = [];
        $currentId = $parentId;
        for ($depth = 0; $currentId !== null; $depth++) {
            if ($depth >= 30 || isset($visited[$currentId])) {
                throw ValidationException::withMessages(['parent_id' => 'Invalid category hierarchy.']);
            }
            $visited[$currentId] = true;
            $parent = TourCategory::query()->find($currentId);
            if (!$parent instanceof TourCategory || $parent->status !== PublicationStatus::Published) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Publish every ancestor category before publishing this category.',
                ]);
            }
            $currentId = $parent->parent_id;
        }
    }

    private function optionalId(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
