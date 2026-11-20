<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsRole;
use App\Models\Travel\Tour;
use App\Models\Travel\TourTag;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AdminTourEditorialEnhancementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_guest_and_customer_cannot_access_tour_editor_or_tag_suggestions(): void
    {
        $this->get('/admin/tours/create')->assertRedirect(route('login'));
        $this->getJson('/admin/internal/tour-tags/suggest')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->get('/admin/tours/create')->assertForbidden();
        $this->getJson('/admin/internal/tour-tags/suggest')->assertForbidden();
    }

    public function test_editor_gets_full_page_create_and_edit_with_correct_permissions(): void
    {
        $editor = $this->actor('content_editor');
        $tour = $this->tour();
        $this->actingAs($editor)->get('/admin/tours/create')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('admin/tours/editor')
                ->where('mode', 'create')->where('can.publish', false)
        );
        $this->get('/admin/tours/'.$tour->id.'/edit')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('admin/tours/editor')
                ->where('mode', 'edit')->where('tourId', $tour->id)
        );
    }

    public function test_read_only_role_cannot_create_or_edit(): void
    {
        $tour = $this->tour();
        $this->actingAs($this->actor('designer'))
            ->get('/admin/tours/create')->assertForbidden();
        $this->get('/admin/tours/'.$tour->id.'/edit')->assertForbidden();
    }

    public function test_tag_create_is_atomic_and_existing_names_reused_case_insensitively(): void
    {
        $this->actingAs($this->actor('content_editor'));
        $this->postJson('/admin/internal/tours', [
            'title' => 'Tour With Tags', 'slug' => 'tour-with-tags', 'status' => 'draft',
            'tag_names' => ['Adventure', 'Cultural', ' adventure '],
        ])->assertCreated()->assertJsonCount(2, 'data.tag_names');
        self::assertSame(2, TourTag::query()->count());
        $this->postJson('/admin/internal/tours', [
            'title' => 'Another Tagged Tour', 'slug' => 'another-tagged-tour',
            'tag_names' => ['ADVENTURE'],
        ])->assertCreated();
        self::assertSame(2, TourTag::query()->count());
        $this->getJson('/admin/internal/tour-tags/suggest?q=adv')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Adventure');
    }

    public function test_tags_can_be_cleared_and_change_is_audited(): void
    {
        $editor = $this->actor('content_editor');
        $tour = $this->tour();
        $this->actingAs($editor)->putJson('/admin/internal/tours/'.$tour->id, [
            'tag_names' => ['Sundarbans', 'Family'],
        ])->assertOk()->assertJsonCount(2, 'data.tag_names');
        $this->assertDatabaseHas('cms_audit_events', ['action' => 'tour.tags.changed']);
        $this->putJson('/admin/internal/tours/'.$tour->id, ['tag_names' => []])
            ->assertOk()->assertJsonPath('data.tag_names', []);
        $this->assertDatabaseCount('travel_tour_tag', 0);
    }

    public function test_duplicate_invalid_tag_names_cannot_create_partial_tour(): void
    {
        $this->actingAs($this->actor('content_editor'))
            ->postJson('/admin/internal/tours', [
                'title' => 'Bad Tagged Tour', 'slug' => 'bad-tagged-tour',
                'tag_names' => ['Good', '<script>'],
            ])->assertUnprocessable()->assertJsonValidationErrors('tag_names');
        $this->assertDatabaseMissing('travel_tours', ['slug' => 'bad-tagged-tour']);
        $this->assertDatabaseCount('travel_tour_tags', 0);
    }

    public function test_html_sanitization_removes_scripts_handlers_and_remote_images(): void
    {
        $this->actingAs($this->actor('content_editor'));
        $response = $this->postJson('/admin/internal/tours', [
            'title' => 'Safe Content Tour', 'slug' => 'safe-content-tour',
            'description' => '<h2 onclick="alert(1)">Visit</h2><script>alert(1)</script><p><a href="javascript:alert(1)">bad</a></p><img src="https://evil.example/x.jpg" onerror="alert(1)">',
        ])->assertCreated();
        $id = (int) $response->json('data.id');
        $description = (string) Tour::query()->findOrFail($id)->getAttribute('description');
        self::assertStringContainsString('<h2>Visit</h2>', $description);
        self::assertStringNotContainsString('script', $description);
        self::assertStringNotContainsString('onclick', $description);
        self::assertStringNotContainsString('javascript:', $description);
        self::assertStringNotContainsString('evil.example', $description);
    }

    public function test_draft_editor_can_upload_gallery_and_set_featured(): void
    {
        if (! function_exists('imagecreatefromstring')) self::markTestSkipped('GD required for image processing.');
        Storage::fake('public');
        $tour = $this->tour();
        $this->actingAs($this->actor('content_editor'));
        $first = $this->post('/admin/internal/tours/'.$tour->id.'/media', [
            'image' => UploadedFile::fake()->image('first.jpg', 320, 240),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.is_featured', true);
        $firstId = (int) $first->json('data.id');
        $second = $this->post('/admin/internal/tours/'.$tour->id.'/media', [
            'image' => UploadedFile::fake()->image('second.png', 320, 240),
            'featured' => '1',
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.is_featured', true);
        self::assertSame(1, \App\Models\Travel\TourMedia::query()->where('tour_id', $tour->id)->where('is_featured', true)->count());
        $this->patchJson('/admin/internal/tours/'.$tour->id.'/media/'.$firstId, ['is_featured' => true])
            ->assertOk()->assertJsonPath('data.is_featured', true);
        self::assertSame(1, \App\Models\Travel\TourMedia::query()->where('tour_id', $tour->id)->where('is_featured', true)->count());
        $this->getJson('/admin/internal/tours/'.$tour->id.'/media')->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseHas('cms_audit_events', ['action' => 'tour.media.uploaded']);
    }

    public function test_media_cannot_be_modified_using_a_different_tour_id(): void
    {
        if (! function_exists('imagecreatefromstring')) self::markTestSkipped('GD required.');
        Storage::fake('public');
        $this->actingAs($this->actor('content_editor'));
        $original = $this->tour();
        $other = Tour::query()->create(['title' => 'Other Tour', 'slug' => 'other-tour']);
        $response = $this->post('/admin/internal/tours/'.$original->id.'/media', [
            'image' => UploadedFile::fake()->image('photo.jpg', 200, 200),
        ], ['Accept' => 'application/json'])->assertCreated();
        $id = $response->json('data.id');
        $this->patchJson('/admin/internal/tours/'.$other->id.'/media/'.$id, ['alt_text' => 'Wrong tour'])
            ->assertNotFound();
        $this->deleteJson('/admin/internal/tours/'.$other->id.'/media/'.$id)->assertNotFound();
    }

    public function test_description_references_block_image_deletion_until_removed(): void
    {
        if (! function_exists('imagecreatefromstring')) self::markTestSkipped('GD required.');
        Storage::fake('public');
        $this->actingAs($this->actor('content_editor'));
        $tour = $this->tour();
        $photo = $this->post('/admin/internal/tours/'.$tour->id.'/media', [
            'image' => UploadedFile::fake()->image('photo.jpg', 200, 200),
        ], ['Accept' => 'application/json'])->assertCreated();
        $url = (string) $photo->json('data.url');
        $imageId = (int) $photo->json('data.id');
        $this->putJson('/admin/internal/tours/'.$tour->id, [
            'description' => '<p>See below</p><img src="'.$url.'" alt="View">',
        ])->assertOk();
        $this->deleteJson('/admin/internal/tours/'.$tour->id.'/media/'.$imageId)
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->putJson('/admin/internal/tours/'.$tour->id, ['description' => '<p>Removed image</p>'])->assertOk();
        $this->deleteJson('/admin/internal/tours/'.$tour->id.'/media/'.$imageId)->assertNoContent();
    }

    public function test_content_editor_cannot_change_media_for_published_tour_without_publish_permission(): void
    {
        $tour = $this->tour(['status' => 'published', 'published_at' => now()]);
        $this->actingAs($this->actor('content_editor'));
        $this->get('/admin/tours/'.$tour->id.'/edit')->assertForbidden();
        $this->post('/admin/internal/tours/'.$tour->id.'/media', [], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_react_ui_uses_single_native_sidebar_shared_feedback_and_tiptap(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/pages/admin/tours/editor.tsx'));
        self::assertStringContainsString('lg:col-span-9', $source);
        self::assertStringContainsString('lg:col-span-3', $source);
        self::assertStringContainsString('TiptapEditor', $source);
        self::assertStringContainsString('confirmAction(', $source);
        self::assertStringContainsString('toast.success(', $source);
        self::assertStringContainsString('<BablysAdminLayout>', $source);
        self::assertStringNotContainsString('window.confirm(', $source);
        self::assertStringNotContainsString('<CmsSidebarMenu', $source);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        CmsRole::query()->where('key', $role)->firstOrFail()->users()->attach($user->getKey());
        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function tour(array $attributes = []): Tour
    {
        return Tour::query()->create(array_merge(['title' => 'Example Tour', 'slug' => 'example-tour'], $attributes));
    }
}
