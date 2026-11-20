<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Tours\PublishedTourCatalog;
use App\Models\Travel\Tour;
use App\Models\Travel\TourMedia;
use App\Models\Travel\TourTag;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Bablys\TourQaTestCase;

/** P3D.4: integrated editor -> service -> database -> catalog journeys. */
final class CmsTourEndToEndJourneyTest extends TourQaTestCase
{
    public function test_draft_creation_persists_taxonomy_tags_safe_html_and_audit_but_is_not_public(): void
    {
        $category = $this->category('family', 'published');
        $destination = $this->destination('cox-bazar', 'published');
        $data = $this->payload('family-escape');
        $data['category_ids'] = [$category->id];
        $data['destination_ids'] = [$destination->id];
        $data['tag_names'] = ['Family', 'Beach'];
        $data['description'] = '<h2>Discover the coast</h2><p>Three days</p>';

        $actor = $this->staff('content_editor');
        $response = $this->actingAs($actor)->postJson('/admin/internal/tours', $data)
            ->assertCreated()->assertJsonPath('data.status', 'draft')
            ->assertJsonCount(2, 'data.tag_names');
        $id = (int) $response->json('data.id');
        $this->getJson('/admin/internal/tours/'.$id)->assertOk()
            ->assertJsonPath('data.category_ids.0', $category->id)
            ->assertJsonPath('data.destination_ids.0', $destination->id);
        $this->assertDatabaseHas('travel_tour_tag', ['tour_id' => $id]);
        $this->assertDatabaseHas('cms_audit_events', [
            'actor_id' => $actor->getKey(), 'action' => 'tour.created',
            'subject_type' => 'tour', 'subject_id' => (string) $id,
        ]);
        self::assertSame([], app(PublishedTourCatalog::class)->tours());
    }

    public function test_administrator_publishes_ready_draft_and_catalog_returns_only_public_fields(): void
    {
        $category = $this->category('nature', 'published');
        $destination = $this->destination('sylhet', 'published');
        $data = $this->payload('green-retreat');
        $data['category_ids'] = [$category->id];
        $data['destination_ids'] = [$destination->id];
        $data['tag_names'] = ['Nature'];
        $data['is_featured'] = true;
        $admin = $this->staff('administrator');
        $created = $this->actingAs($admin)->postJson('/admin/internal/tours', $data)->assertCreated();
        $id = (int) $created->json('data.id');

        $this->putJson('/admin/internal/tours/'.$id, ['status' => 'published'])
            ->assertOk()->assertJsonPath('data.status', 'published');
        $listed = app(PublishedTourCatalog::class)->tours('nature', 'sylhet', 12, true);
        self::assertCount(1, $listed);
        self::assertSame($id, $listed[0]['id']);
        self::assertArrayNotHasKey('price', $listed[0]);
        self::assertArrayNotHasKey('availability', $listed[0]);
        self::assertNotNull(Tour::query()->findOrFail($id)->published_at);
    }

    public function test_unpublishing_removes_tour_from_catalog_immediately(): void
    {
        $category = $this->category('hills', 'published');
        $destination = $this->destination('bandarban', 'published');
        $admin = $this->staff('administrator');
        $data = $this->payload('hill-trek');
        $data['status'] = 'published';
        $data['category_ids'] = [$category->id];
        $data['destination_ids'] = [$destination->id];
        $created = $this->actingAs($admin)->postJson('/admin/internal/tours', $data)->assertCreated();
        $id = (int) $created->json('data.id');
        self::assertCount(1, app(PublishedTourCatalog::class)->tours());
        $this->putJson('/admin/internal/tours/'.$id, ['status' => 'draft'])->assertOk();
        self::assertSame([], app(PublishedTourCatalog::class)->tours());
        self::assertNull(Tour::query()->findOrFail($id)->published_at);
    }

    public function test_tags_reuse_one_database_record_and_are_suggested_across_tours(): void
    {
        $this->actingAs($this->staff('content_editor'));
        $a = $this->payload('tagged-one');
        $a['tag_names'] = ['Adventure', 'Culture'];
        $this->postJson('/admin/internal/tours', $a)->assertCreated();
        $b = $this->payload('tagged-two');
        $b['tag_names'] = [' adventure ', 'Photography'];
        $this->postJson('/admin/internal/tours', $b)->assertCreated();
        self::assertSame(3, TourTag::query()->count());
        $this->getJson('/admin/internal/tour-tags/suggest?q=adv')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Adventure');
    }

    public function test_tiptap_markup_is_sanitized_on_write_and_stays_sanitized_after_read(): void
    {
        $data = $this->payload('html-proof');
        $data['description'] = '<h2 onclick="alert(1)">Tour</h2>'
            .'<script>dangerous()</script>'
            .'<p><a href="javascript:alert(1)">Bad link</a></p>'
            .'<img src="https://outside.example/photo.jpg" onerror="alert(1)">';
        $response = $this->actingAs($this->staff('content_editor'))
            ->postJson('/admin/internal/tours', $data)->assertCreated();
        $id = (int) $response->json('data.id');
        $body = (string) $this->getJson('/admin/internal/tours/'.$id)
            ->assertOk()->json('data.description');
        self::assertStringContainsString('<h2>Tour</h2>', $body);
        self::assertStringNotContainsString('<script', $body);
        self::assertStringNotContainsString('onclick', $body);
        self::assertStringNotContainsString('javascript:', $body);
        self::assertStringNotContainsString('outside.example', $body);
        self::assertSame($body, (string) Tour::query()->findOrFail($id)->description);
    }

    public function test_gallery_featured_lifecycle_tracks_files_and_audit(): void
    {
        if (! function_exists('imagecreatefromstring')) {
            self::markTestSkipped('GD extension required for image lifecycle tests.');
        }
        Storage::fake('public');
        $tour = $this->tour('gallery-journey');
        $this->actingAs($this->staff('content_editor'));
        $first = $this->post('/admin/internal/tours/'.$tour->id.'/media', [
            'image' => UploadedFile::fake()->image('coast.jpg', 240, 180),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.is_featured', true);
        $firstId = (int) $first->json('data.id');
        $firstPath = (string) $first->json('data.path');
        $second = $this->post('/admin/internal/tours/'.$tour->id.'/media', [
            'image' => UploadedFile::fake()->image('hills.png', 240, 180), 'featured' => '1',
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.is_featured', true);
        $secondId = (int) $second->json('data.id');
        $secondPath = (string) $second->json('data.path');
        Storage::disk('public')->assertExists([$firstPath, $secondPath]);
        $this->patchJson('/admin/internal/tours/'.$tour->id.'/media/'.$firstId, [
            'alt_text' => 'Coxs Bazar scenery', 'sort_order' => 3,
        ])->assertOk()->assertJsonPath('data.alt_text', 'Coxs Bazar scenery');
        $this->deleteJson('/admin/internal/tours/'.$tour->id.'/media/'.$secondId)->assertNoContent();
        Storage::disk('public')->assertMissing($secondPath);
        self::assertTrue((bool) TourMedia::query()->findOrFail($firstId)->is_featured);
        $this->assertDatabaseHas('cms_audit_events', ['action' => 'tour.media.deleted']);
    }

    public function test_full_page_editor_is_responsive_and_uses_shared_admin_infrastructure(): void
    {
        $admin = $this->staff('administrator');
        $tour = $this->tour('edit-layout');
        $this->actingAs($admin)->get('/admin/tours/create')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('admin/tours/editor')->where('mode', 'create')
        );
        $this->get('/admin/tours/'.$tour->id.'/edit')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('admin/tours/editor')->where('tourId', $tour->id)
        );
        $source = (string) file_get_contents(base_path('resources/js/pages/admin/tours/editor.tsx'));
        foreach (['lg:col-span-9', 'lg:col-span-3', 'TiptapEditor', 'confirmAction(', 'toast.success(', '<BablysAdminLayout>'] as $expected) {
            self::assertStringContainsString($expected, $source);
        }
        self::assertStringNotContainsString('<CmsSidebarMenu', $source);
        self::assertStringNotContainsString('window.confirm(', $source);
    }
}
