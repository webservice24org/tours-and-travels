<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Tours\PublishedTourDetail;
use App\Models\Travel\TourMedia;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bablys\TourBookingQaTestCase;

final class PublicTourDetailFoundationTest extends TourBookingQaTestCase
{
    public function test_guest_can_open_published_tour_with_only_allowlisted_public_fields(): void
    {
        [$tour] = $this->readyTour('p3f3a-published');
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->component('public/tours/show')
            ->where('tour.title', $tour->title)
            ->where('tour.slug', $tour->slug)
            ->where('tour.duration_days', (int) $tour->duration_days)
            ->where('tour.cover_image', null)
            ->has('tour.destinations', 1)
            ->has('tour.categories', 1)
            ->missing('tour.id')
            ->missing('tour.price')
            ->missing('tour.departures')
            ->missing('tour.seats_committed')
            ->missing('tour.booking_reference')
        );
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_draft_scheduled_and_archived_tours_are_not_readable_as_details(): void
    {
        [$draft] = $this->readyTour('p3f3a-draft');
        [$scheduled] = $this->readyTour('p3f3a-scheduled');
        [$archived] = $this->readyTour('p3f3a-archived');
        $draft->update(['status' => 'draft', 'published_at' => null]);
        $scheduled->update(['status' => 'scheduled', 'published_at' => null, 'scheduled_for' => now()->addDay()]);
        $archived->update(['status' => 'archived', 'published_at' => null]);
        $this->get('/tours/'.$draft->slug)->assertNotFound();
        $this->get('/tours/'.$scheduled->slug)->assertNotFound();
        $this->get('/tours/'.$archived->slug)->assertNotFound();
    }

    public function test_unpublished_taxonomy_cannot_leak_on_detail(): void
    {
        [$tour] = $this->readyTour('p3f3a-taxonomy');
        $destination = $tour->destinations()->firstOrFail();
        $category = $tour->categories()->firstOrFail();
        $destination->update(['status' => 'archived']);
        $category->update(['status' => 'archived']);
        $response = $this->get('/tours/'.$tour->slug);
        // Some Tour::publiclyAvailable scopes exclude the complete tour when
        // taxonomy is archived; others retain it but filter its label.
        if ($response->status() === 200) {
            $response->assertInertia(fn ($page) => $page
                ->has('tour.destinations', 0)
                ->has('tour.categories', 0));
        } else {
            $response->assertNotFound();
        }
    }

    public function test_unknown_and_invalid_slug_are_404(): void
    {
        $this->get('/tours/does-not-exist-abc')->assertNotFound();
        $this->get('/tours/INVALID')->assertNotFound();
    }

    public function test_only_featured_public_disk_image_is_readable_as_cover(): void
    {
        Storage::fake('public');
        [$tour] = $this->readyTour('p3f3a-cover');
        Storage::disk('public')->put('tours/p3f3a.webp', 'safe-photo');
        TourMedia::query()->create([
            'tour_id' => $tour->id,
            'disk' => 'public',
            'path' => 'tours/p3f3a.webp',
            'mime_type' => 'image/webp',
            'file_size' => 900,
            'width' => 1200,
            'height' => 800,
            'alt_text' => 'Green hills by the sea',
            'is_featured' => true,
            'sort_order' => 0,
        ]);
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.cover_image.alt', 'Green hills by the sea')
            ->where('tour.cover_image.width', 1200)
            ->missing('tour.cover_image.disk')
            ->missing('tour.cover_image.path'));
    }

    public function test_private_file_is_never_published_and_enquiry_remains_nonbinding(): void
    {
        Storage::fake('public');
        [$tour] = $this->readyTour('p3f3a-private');
        TourMedia::query()->create([
            'tour_id' => $tour->id, 'disk' => 'local', 'path' => 'private/secret.webp',
            'mime_type' => 'image/webp', 'file_size' => 100,
            'width' => 100, 'height' => 100,
            'is_featured' => true, 'sort_order' => 0,
        ]);
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.cover_image', null)
            ->missing('tour.media')
            ->missing('tour.payment_state')
            ->missing('tour.contact_email_encrypted'));
        $this->get('/tours/'.$tour->slug.'/booking-request')->assertOk()
            ->assertInertia(fn ($page) => $page->component('public/booking-requests/create'));
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_detail_service_returns_null_for_missing_slug(): void
    {
        self::assertNull(app(PublishedTourDetail::class)->find('not-in-catalog'));
    }
}
