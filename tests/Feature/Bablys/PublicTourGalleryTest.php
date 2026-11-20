<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Tours\PublishedTourDetail;
use App\Models\Travel\Tour;
use App\Models\Travel\TourMedia;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bablys\TourBookingQaTestCase;

/** P3F.3B.1: published-gallery allowlist and nonbinding/public-only contract. */
final class PublicTourGalleryTest extends TourBookingQaTestCase
{
    private function media(Tour $tour, string $path, string $mime = 'image/webp', array $overrides = []): TourMedia
    {
        return TourMedia::query()->create(array_merge([
            'tour_id' => (int) $tour->getKey(),
            'disk' => 'public',
            'path' => $path,
            'mime_type' => $mime,
            'file_size' => 1000,
            'width' => 1200,
            'height' => 800,
            'alt_text' => 'Gallery photograph',
            'sort_order' => 0,
            'is_featured' => false,
        ], $overrides));
    }

    public function test_published_gallery_only_exposes_safe_public_fields_and_featured_order(): void
    {
        Storage::fake('public');
        [$tour] = $this->readyTour('p3f3b-gallery');
        Storage::disk('public')->put('tours/p3f3b-one.webp', 'image-one');
        Storage::disk('public')->put('tours/p3f3b-two.webp', 'image-two');
        $this->media($tour, 'tours/p3f3b-one.webp', overrides: ['sort_order' => 1, 'alt_text' => 'First view']);
        $this->media($tour, 'tours/p3f3b-two.webp', overrides: ['is_featured' => true, 'alt_text' => 'Featured view']);

        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->component('public/tours/show')
            ->has('tour.gallery', 2)
            ->where('tour.gallery.0.alt', 'Featured view')
            ->where('tour.gallery.1.alt', 'First view')
            ->missing('tour.gallery.0.path')
            ->missing('tour.gallery.0.disk')
            ->missing('tour.gallery.0.uploaded_by')
            ->missing('tour.seats_committed')
            ->missing('tour.price')
        );
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_private_disk_and_missing_objects_are_not_exposed(): void
    {
        Storage::fake('public');
        [$tour] = $this->readyTour('p3f3b-private');
        Storage::disk('public')->put('tours/p3f3b-safe.webp', 'photo');
        $this->media($tour, 'private/sensitive.webp', overrides: ['disk' => 'local', 'is_featured' => true]);
        $this->media($tour, 'tours/p3f3b-missing.webp', overrides: ['sort_order' => 1]);
        $this->media($tour, 'tours/p3f3b-safe.webp', overrides: ['sort_order' => 2, 'alt_text' => 'Safe photo']);

        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->has('tour.gallery', 1)
            ->where('tour.gallery.0.alt', 'Safe photo')
            ->missing('tour.gallery.0.path')
        )->assertDontSee('private/sensitive.webp');
    }

    public function test_svg_wrong_extension_and_traversal_paths_are_skipped(): void
    {
        Storage::fake('public');
        [$tour] = $this->readyTour('p3f3b-bad-path');
        Storage::disk('public')->put('tours/p3f3b-real.webp', 'photo');
        $this->media($tour, 'tours/p3f3b-vector.svg', 'image/svg+xml');
        $this->media($tour, 'tours/p3f3b-mismatch.svg', 'image/webp');
        $this->media($tour, 'tours/../traversal.webp', 'image/webp');
        $this->media($tour, 'tours/p3f3b-real.webp', 'image/webp');

        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->has('tour.gallery', 1)
            ->where('tour.gallery.0.width', 1200));
    }

    public function test_zero_size_or_dimensions_and_missing_public_visibility_are_rejected(): void
    {
        Storage::fake('public');
        [$tour] = $this->readyTour('p3f3b-invalid-metadata');
        Storage::disk('public')->put('tours/p3f3b-zero.webp', 'photo');
        $this->media($tour, 'tours/p3f3b-zero.webp', overrides: ['file_size' => 0]);
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page->has('tour.gallery', 0));
        TourMedia::query()->where('tour_id', $tour->getKey())->update(['file_size' => 1000, 'width' => 0]);
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page->has('tour.gallery', 0));
        TourMedia::query()->where('tour_id', $tour->getKey())->update(['width' => 1200]);
        config()->set('filesystems.disks.public.visibility', 'private');
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page->has('tour.gallery', 0));
    }

    public function test_empty_alt_uses_public_tour_title_without_raw_html(): void
    {
        Storage::fake('public');
        [$tour] = $this->readyTour('p3f3b-alt');
        Storage::disk('public')->put('tours/p3f3b-alt.webp', 'photo');
        $this->media($tour, 'tours/p3f3b-alt.webp', overrides: ['alt_text' => '<b>Hill</b> view']);
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.gallery.0.alt', 'Hill view'));
        TourMedia::query()->where('tour_id', $tour->getKey())->update(['alt_text' => '']);
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.gallery.0.alt', $tour->title.' tour photograph'));
    }

    public function test_gallery_is_capped_to_twelve_and_ordered_deterministically(): void
    {
        Storage::fake('public');
        [$tour] = $this->readyTour('p3f3b-limit');
        for ($n = 0; $n < 15; $n++) {
            $path = sprintf('tours/p3f3b-%02d.webp', $n);
            Storage::disk('public')->put($path, 'photo');
            $this->media($tour, $path, overrides: ['sort_order' => $n, 'alt_text' => 'Photo '.$n]);
        }
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->has('tour.gallery', 12)
            ->where('tour.gallery.0.alt', 'Photo 0')
            ->where('tour.gallery.11.alt', 'Photo 11'));
    }

    public function test_unpublished_tour_gallery_stays_unavailable_to_guests(): void
    {
        Storage::fake('public');
        [$tour] = $this->readyTour('p3f3b-draft');
        Storage::disk('public')->put('tours/p3f3b-draft.webp', 'photo');
        $this->media($tour, 'tours/p3f3b-draft.webp');
        $tour->update(['status' => 'draft', 'published_at' => null]);
        $this->get('/tours/'.$tour->slug)->assertNotFound();
        self::assertNull(app(PublishedTourDetail::class)->find((string) $tour->slug));
    }

    public function test_gallery_is_nonbinding_even_if_tour_has_departures_and_prices(): void
    {
        Storage::fake('public');
        [$tour] = $this->readyTour('p3f3b-nonbinding');
        Storage::disk('public')->put('tours/p3f3b-safe.webp', 'photo');
        $this->media($tour, 'tours/p3f3b-safe.webp');
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->has('tour.gallery', 1)
            ->where('tour.enquiry_url', route('booking-requests.create', ['tour' => $tour->slug]))
            ->missing('tour.departures')
            ->missing('tour.remaining_capacity')
            ->missing('tour.price'));
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }
}
