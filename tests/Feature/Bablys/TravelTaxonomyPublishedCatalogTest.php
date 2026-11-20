<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Domain\Travel\Taxonomy\PublishedTaxonomyCatalog;
use App\Models\Travel\Destination;
use App\Models\Travel\TourCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TravelTaxonomyPublishedCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_category_list_only_returns_published_top_level_rows(): void
    {
        TourCategory::query()->create(['name' => 'Draft', 'slug' => 'draft']);
        $parent = TourCategory::query()->create([
            'name' => 'Public', 'slug' => 'public', 'status' => PublicationStatus::Published,
        ]);
        TourCategory::query()->create([
            'name' => 'Child', 'slug' => 'child', 'parent_id' => $parent->id,
            'status' => PublicationStatus::Published,
        ]);
        TourCategory::query()->create([
            'name' => 'Archived', 'slug' => 'archived', 'status' => PublicationStatus::Archived,
        ]);
        self::assertSame(['public'], array_column(app(PublishedTaxonomyCatalog::class)->topLevelCategories(), 'slug'));
    }

    public function test_public_destinations_include_only_published_country_matches(): void
    {
        Destination::query()->create([
            'name' => 'Coxs Bazar', 'slug' => 'coxs', 'country_code' => 'BD',
            'status' => PublicationStatus::Published,
        ]);
        Destination::query()->create([
            'name' => 'Phuket', 'slug' => 'phuket', 'country_code' => 'TH',
            'status' => PublicationStatus::Published,
        ]);
        Destination::query()->create(['name' => 'Hidden', 'slug' => 'hidden', 'country_code' => 'BD']);

        self::assertSame(['coxs'], array_column(app(PublishedTaxonomyCatalog::class)->destinations('bd'), 'slug'));
    }

    public function test_catalog_sorting_is_deterministic_and_limit_is_clamped(): void
    {
        foreach ([['zeta', 2], ['beta', 1], ['alpha', 1]] as [$slug, $order]) {
            TourCategory::query()->create([
                'name' => ucfirst($slug), 'slug' => $slug,
                'status' => PublicationStatus::Published, 'sort_order' => $order,
            ]);
        }

        $catalog = app(PublishedTaxonomyCatalog::class);
        self::assertSame(['beta', 'alpha', 'zeta'], array_column($catalog->topLevelCategories(), 'slug'));
        self::assertCount(1, $catalog->topLevelCategories(0));
    }

    public function test_public_catalog_excludes_soft_deleted_records(): void
    {
        $destination = Destination::query()->create([
            'name' => 'Khulna', 'slug' => 'khulna', 'country_code' => 'BD',
            'status' => PublicationStatus::Published,
        ]);
        $destination->delete();
        self::assertSame([], app(PublishedTaxonomyCatalog::class)->destinations());
    }
}
