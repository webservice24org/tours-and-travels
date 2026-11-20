<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Domain\Travel\Taxonomy\CategoryHierarchyGuard;
use App\Domain\Travel\Taxonomy\TaxonomySlug;
use App\Models\Travel\TourCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class TravelTaxonomyDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_slug_normalizes_case_and_outer_spaces(): void
    {
        self::assertSame('family-tours', app(TaxonomySlug::class)->normalize('  FAMILY-TOURS  '));
    }

    public function test_slug_rejects_reserved_routes(): void
    {
        $this->expectException(ValidationException::class);
        app(TaxonomySlug::class)->normalize('admin');
    }

    public function test_slug_rejects_arbitrary_path_and_spaces(): void
    {
        $this->expectException(ValidationException::class);
        app(TaxonomySlug::class)->normalize('tour/../example');
    }

    public function test_rejects_nonexistent_or_deleted_parent(): void
    {
        $parent = TourCategory::query()->create(['name' => 'Old', 'slug' => 'old']);
        $parent->delete();

        $this->expectException(ValidationException::class);
        app(CategoryHierarchyGuard::class)->assertValidParent(null, (int) $parent->id);
    }

    public function test_prevents_assigning_category_to_itself(): void
    {
        $category = TourCategory::query()->create(['name' => 'Tour', 'slug' => 'tour']);
        $this->expectException(ValidationException::class);
        app(CategoryHierarchyGuard::class)->assertValidParent((int) $category->id, (int) $category->id);
    }

    public function test_prevents_parent_descendant_cycle(): void
    {
        $root = TourCategory::query()->create(['name' => 'Root', 'slug' => 'root']);
        $child = TourCategory::query()->create([
            'name' => 'Child', 'slug' => 'child', 'parent_id' => $root->id,
        ]);
        $leaf = TourCategory::query()->create([
            'name' => 'Leaf', 'slug' => 'leaf', 'parent_id' => $child->id,
        ]);

        $this->expectException(ValidationException::class);
        app(CategoryHierarchyGuard::class)->assertValidParent((int) $root->id, (int) $leaf->id);
    }

    public function test_rejects_archived_direct_parent(): void
    {
        $parent = TourCategory::query()->create([
            'name' => 'Archived', 'slug' => 'archived', 'status' => PublicationStatus::Archived,
        ]);

        $this->expectException(ValidationException::class);
        app(CategoryHierarchyGuard::class)->assertValidParent(null, (int) $parent->id);
    }

    public function test_allows_safe_reparenting_and_nullable_parent(): void
    {
        $parent = TourCategory::query()->create(['name' => 'Safe', 'slug' => 'safe']);
        $child = TourCategory::query()->create(['name' => 'Moving', 'slug' => 'moving']);
        app(CategoryHierarchyGuard::class)->assertValidParent((int) $child->id, (int) $parent->id);
        app(CategoryHierarchyGuard::class)->assertValidParent((int) $child->id, null);
        $this->addToAssertionCount(2);
    }

    public function test_prevents_soft_delete_of_category_with_children(): void
    {
        $parent = TourCategory::query()->create(['name' => 'Parent', 'slug' => 'parent']);
        TourCategory::query()->create(['name' => 'Child', 'slug' => 'child', 'parent_id' => $parent->id]);
        $this->expectException(ValidationException::class);
        app(CategoryHierarchyGuard::class)->assertCanSoftDelete($parent);
    }
}
