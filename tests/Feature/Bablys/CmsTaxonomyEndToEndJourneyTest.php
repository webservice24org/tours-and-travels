<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Taxonomy\PublishedTaxonomyCatalog;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Cross-module journeys through the existing P3A–P3B.5 Bablys contracts. */
final class CmsTaxonomyEndToEndJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_editor_can_open_both_inertia_pages_and_discover_both_navigation_links(): void
    {
        $editor = $this->editor();
        $this->actingAs($editor)->get('/admin/tour-categories')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/tour-categories/index'));
        $this->actingAs($editor)->get('/admin/destinations')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/destinations/index'));
        $this->actingAs($editor)->getJson('/admin/internal/navigation')->assertOk()
            ->assertJsonFragment(['href' => '/admin/tour-categories'])
            ->assertJsonFragment(['href' => '/admin/destinations']);
    }

    public function test_editor_can_create_both_taxonomies_and_audit_events_have_the_real_actor(): void
    {
        $editor = $this->editor();
        $category = $this->actingAs($editor)->postJson('/admin/internal/tour-categories', $this->category('family-tours'))
            ->assertCreated()->assertJsonPath('data.slug', 'family-tours');
        $destination = $this->actingAs($editor)->postJson('/admin/internal/destinations', $this->destination('coxs-bazar'))
            ->assertCreated()->assertJsonPath('data.country_code', 'BD');

        $this->assertDatabaseHas('cms_audit_events', [
            'actor_id' => $editor->getKey(), 'action' => 'taxonomy.category.created',
            'subject_type' => 'tour_category', 'subject_id' => (string) $category->json('data.id'),
        ]);
        $this->assertDatabaseHas('cms_audit_events', [
            'actor_id' => $editor->getKey(), 'action' => 'taxonomy.destination.created',
            'subject_type' => 'destination', 'subject_id' => (string) $destination->json('data.id'),
        ]);
        self::assertSame(2, DB::table('cms_audit_events')->count());
    }

    public function test_publishing_both_taxonomies_updates_only_approved_public_catalog_sources(): void
    {
        $editor = $this->editor();
        $visibleCategory = $this->actingAs($editor)
            ->postJson('/admin/internal/tour-categories', $this->category('visible-category'))
            ->assertCreated();
        $this->actingAs($editor)
            ->postJson('/admin/internal/tour-categories', $this->category('hidden-category'))
            ->assertCreated();
        $visibleDestination = $this->actingAs($editor)
            ->postJson('/admin/internal/destinations', $this->destination('visible-destination'))
            ->assertCreated();
        $this->actingAs($editor)
            ->postJson('/admin/internal/destinations', $this->destination('hidden-destination'))
            ->assertCreated();

        $this->actingAs($editor)->putJson('/admin/internal/tour-categories/'.$visibleCategory->json('data.id'), [
            'status' => 'published',
        ])->assertOk();
        $this->actingAs($editor)->putJson('/admin/internal/destinations/'.$visibleDestination->json('data.id'), [
            'status' => 'published',
        ])->assertOk();

        $catalog = app(PublishedTaxonomyCatalog::class);
        self::assertSame(['visible-category'], array_column($catalog->topLevelCategories(), 'slug'));
        self::assertSame(['visible-destination'], array_column($catalog->destinations('bd'), 'slug'));
    }

    public function test_admin_filters_for_categories_and_destinations_are_independent(): void
    {
        $editor = $this->editor();
        $this->actingAs($editor)->postJson('/admin/internal/tour-categories', $this->category('bangladesh-tours'))
            ->assertCreated();
        $this->actingAs($editor)->postJson('/admin/internal/destinations', $this->destination('coxs-bazar'))
            ->assertCreated();
        $this->actingAs($editor)->postJson('/admin/internal/destinations', [
            ...$this->destination('phuket'), 'country_code' => 'TH',
        ])->assertCreated();

        $this->actingAs($editor)->getJson('/admin/internal/tour-categories?search=bangladesh&root_only=1')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'bangladesh-tours');
        $this->actingAs($editor)->getJson('/admin/internal/destinations?country_code=th&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'phuket');
    }

    public function test_destination_coordinates_round_trip_then_clear_as_a_pair(): void
    {
        $editor = $this->editor();
        $data = [
            ...$this->destination('mapped-sylhet'),
            'latitude' => '24.8949000', 'longitude' => '91.8687000',
        ];
        $response = $this->actingAs($editor)->postJson('/admin/internal/destinations', $data)
            ->assertCreated();
        $url = '/admin/internal/destinations/'.$response->json('data.id');
        $this->actingAs($editor)->getJson($url)->assertOk()
            ->assertJsonPath('data.latitude', '24.8949000')
            ->assertJsonPath('data.longitude', '91.8687000');
        $this->actingAs($editor)->putJson($url, [
            'latitude' => null, 'longitude' => null,
        ])->assertOk()->assertJsonPath('data.latitude', null)->assertJsonPath('data.longitude', null);
    }

    public function test_audit_log_tracks_updates_without_leaking_text_bodies(): void
    {
        $editor = $this->editor();
        $category = $this->actingAs($editor)->postJson('/admin/internal/tour-categories', $this->category('heritage-tours'))
            ->assertCreated();
        $destination = $this->actingAs($editor)->postJson('/admin/internal/destinations', $this->destination('heritage-city'))
            ->assertCreated();
        $categoryUrl = '/admin/internal/tour-categories/'.$category->json('data.id');
        $destinationUrl = '/admin/internal/destinations/'.$destination->json('data.id');
        $this->actingAs($editor)->putJson($categoryUrl, [
            'summary' => 'Category private draft text for QA',
        ])->assertOk();
        $this->actingAs($editor)->putJson($destinationUrl, [
            'description' => 'Destination private draft text for QA',
        ])->assertOk();

        $this->assertDatabaseHas('cms_audit_events', ['action' => 'taxonomy.category.updated']);
        $this->assertDatabaseHas('cms_audit_events', ['action' => 'taxonomy.destination.updated']);
        $allMetadata = implode(' ', DB::table('cms_audit_events')->pluck('metadata_json')->all());
        self::assertStringNotContainsString('Category private draft text', $allMetadata);
        self::assertStringNotContainsString('Destination private draft text', $allMetadata);
    }

    /** @return array{name:string,slug:string,status:string} */
    private function category(string $slug): array
    {
        return ['name' => 'Tour '.$slug, 'slug' => $slug, 'status' => 'draft'];
    }

    /** @return array{name:string,slug:string,country_code:string,status:string} */
    private function destination(string $slug): array
    {
        return ['name' => 'Destination '.$slug, 'slug' => $slug, 'country_code' => 'BD', 'status' => 'draft'];
    }

    private function editor(): User
    {
        $editor = User::factory()->create(['email_verified_at' => now()]);
        CmsRole::query()->where('key', 'content_editor')->firstOrFail()->users()->attach($editor->getKey());

        return $editor;
    }
}
