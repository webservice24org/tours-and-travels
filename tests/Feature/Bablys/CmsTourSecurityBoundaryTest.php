<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsPermission;
use App\Models\Cms\CmsRole;
use App\Models\Travel\TourMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bablys\TourQaTestCase;

/** P3D.4: staff authorization, revocation, IDOR and upload boundaries. */
final class CmsTourSecurityBoundaryTest extends TourQaTestCase
{
    public function test_guests_cannot_read_private_tour_or_tag_apis_or_upload_media(): void
    {
        $tour = $this->tour('private-draft');
        $this->get('/admin/tours/create')->assertRedirect(route('login'));
        $this->getJson('/admin/internal/tours/'.$tour->id)->assertUnauthorized();
        $this->getJson('/admin/internal/tour-tags/suggest?q=private')->assertUnauthorized();
        $this->getJson('/admin/internal/tours/'.$tour->id.'/media')->assertUnauthorized();
        $this->post('/admin/internal/tours/'.$tour->id.'/media', [], ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }

    public function test_customers_cannot_access_editors_suggestions_or_media(): void
    {
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $tour = $this->tour('customer-private');
        $this->actingAs($customer)->get('/admin/tours/create')->assertForbidden();
        $this->getJson('/admin/internal/tours/'.$tour->id)->assertForbidden();
        $this->getJson('/admin/internal/tour-tags/suggest')->assertForbidden();
        $this->getJson('/admin/internal/tours/'.$tour->id.'/media')->assertForbidden();
        $this->deleteJson('/admin/internal/tours/'.$tour->id)->assertForbidden();
    }

    public function test_read_only_designer_can_see_metadata_but_cannot_mutate_tours_or_gallery(): void
    {
        $tour = $this->tour('read-only-qa');
        $this->actingAs($this->staff('designer'))
            ->getJson('/admin/internal/tours/'.$tour->id)->assertOk();
        $this->getJson('/admin/internal/tour-tags/suggest')->assertOk();
        $this->getJson('/admin/internal/tours/'.$tour->id.'/media')->assertOk();
        $this->get('/admin/tours/create')->assertForbidden();
        $this->putJson('/admin/internal/tours/'.$tour->id, ['title' => 'Tampered'])->assertForbidden();
        $this->post('/admin/internal/tours/'.$tour->id.'/media', [], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_editor_cannot_publish_or_mutate_existing_published_tour_or_its_images(): void
    {
        $tour = $this->tour('published-restricted', [
            'status' => 'published', 'published_at' => now(),
        ]);
        $this->actingAs($this->staff('content_editor'));
        $payload = $this->payload('editor-cannot-publish');
        $payload['status'] = 'published';
        $this->postJson('/admin/internal/tours', $payload)->assertForbidden();
        $this->get('/admin/tours/'.$tour->id.'/edit')->assertForbidden();
        $this->putJson('/admin/internal/tours/'.$tour->id, [
            'tag_names' => ['Unauthorized'],
        ])->assertForbidden();
        $this->post('/admin/internal/tours/'.$tour->id.'/media', [], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->assertDatabaseCount('travel_tour_tags', 0);
    }

    public function test_revoked_tour_view_permission_removes_navigation_and_private_read_access(): void
    {
        $editor = $this->staff('content_editor');
        $role = CmsRole::query()->where('key', 'content_editor')->firstOrFail();
        $permission = CmsPermission::query()->where('key', 'tours.view')->firstOrFail();
        $this->actingAs($editor)->getJson('/admin/internal/navigation')->assertOk()
            ->assertJsonFragment(['href' => '/admin/tours']);
        $role->permissions()->detach($permission->getKey());
        $this->getJson('/admin/internal/navigation')->assertOk()
            ->assertJsonMissing(['href' => '/admin/tours']);
        $this->getJson('/admin/internal/tour-tags/suggest')->assertForbidden();
        $this->get('/admin/tours')->assertForbidden();
    }

    public function test_suspension_immediately_blocks_editor_tag_and_media_requests(): void
    {
        $editor = $this->staff('content_editor');
        $tour = $this->tour('suspended-qa');
        $this->actingAs($editor)->getJson('/admin/internal/tour-tags/suggest')->assertOk();
        $editor->forceFill(['cms_staff_disabled_at' => now()])->save();
        $this->getJson('/admin/internal/tour-tags/suggest')->assertForbidden();
        $this->getJson('/admin/internal/tours/'.$tour->id.'/media')->assertForbidden();
        $this->putJson('/admin/internal/tours/'.$tour->id, ['title' => 'Forbidden'])->assertForbidden();
        self::assertSame('Tour suspended-qa', $tour->refresh()->title);
    }

    public function test_tour_media_cannot_be_updated_or_deleted_from_another_tour(): void
    {
        if (! function_exists('imagecreatefromstring')) {
            self::markTestSkipped('GD extension required for media-scope tests.');
        }
        Storage::fake('public');
        $this->actingAs($this->staff('content_editor'));
        $owner = $this->tour('media-owner');
        $other = $this->tour('media-other');
        $uploaded = $this->post('/admin/internal/tours/'.$owner->id.'/media', [
            'image' => UploadedFile::fake()->image('scope.png', 200, 200),
        ], ['Accept' => 'application/json'])->assertCreated();
        $mediaId = (int) $uploaded->json('data.id');
        $path = (string) $uploaded->json('data.path');
        $this->patchJson('/admin/internal/tours/'.$other->id.'/media/'.$mediaId, [
            'alt_text' => 'Cross-tour tampering',
        ])->assertNotFound();
        $this->deleteJson('/admin/internal/tours/'.$other->id.'/media/'.$mediaId)->assertNotFound();
        self::assertNull(TourMedia::query()->findOrFail($mediaId)->alt_text);
        Storage::disk('public')->assertExists($path);
    }

    public function test_non_image_upload_is_rejected_without_creating_media(): void
    {
        Storage::fake('public');
        $tour = $this->tour('reject-upload');
        $this->actingAs($this->staff('content_editor'))
            ->post('/admin/internal/tours/'.$tour->id.'/media', [
                'image' => UploadedFile::fake()->create('payload.txt', 1, 'text/plain'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertDatabaseCount('travel_tour_media', 0);
    }
}
