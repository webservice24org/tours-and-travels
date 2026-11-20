<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Tours\PublishedTourCatalog;
use App\Models\Travel\TourMedia;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bablys\TourBookingQaTestCase;

final class PublicTourMediaCardsTest extends TourBookingQaTestCase
{
    private function attach(int $tourId, string $path, array $changes = []): TourMedia
    {
        $values = array_merge([
            'tour_id' => $tourId,
            'disk' => 'public',
            'path' => $path,
            'mime_type' => 'image/webp',
            'file_size' => 256,
            'width' => 1200,
            'height' => 800,
            'alt_text' => 'A verified tour photo',
            'sort_order' => 0,
            'is_featured' => false,
        ], $changes);

        return TourMedia::query()->create($values);
    }

    public function test_public_featured_image_is_used_with_safe_url_and_alt(): void
    {
        Storage::fake('public');
        [$tour] = $this->readyTour('p3f2b2-photo');
        Storage::disk('public')->put('tours/ordinary.webp', 'image');
        Storage::disk('public')->put('tours/featured.webp', 'image');
        $this->attach((int) $tour->id, 'tours/ordinary.webp');
        $this->attach((int) $tour->id, 'tours/featured.webp', [
            'is_featured' => true, 'alt_text' => 'Beautiful green valley',
        ]);

        $this->get('/tours')->assertOk()->assertInertia(fn ($page) => $page
            ->has('tours', 1)
            ->where('tours.0.cover_image.alt', 'Beautiful green valley')
            ->where('tours.0.cover_image.width', 1200)
            ->where('tours.0.cover_image.height', 800)
            ->missing('tours.0.cover_image.path')
            ->missing('tours.0.cover_image.disk')
        );
        $cover = app(PublishedTourCatalog::class)->browse(null, null, '', 'featured', 1)['tours'][0]['cover_image'];
        self::assertNotNull($cover);
        self::assertStringEndsWith('/tours/featured.webp', $cover['src']);
    }

    public function test_missing_featured_file_falls_back_to_next_existing_image(): void
    {
        Storage::fake('public');
        [$tour] = $this->readyTour('p3f2b2-missing');
        Storage::disk('public')->put('tours/good.webp', 'image');
        $this->attach((int) $tour->id, 'tours/missing.webp', ['is_featured' => true]);
        $this->attach((int) $tour->id, 'tours/good.webp', ['sort_order' => 3]);

        $this->get('/tours')->assertOk()->assertInertia(fn ($page) => $page
            ->has('tours.0.cover_image.src'));
        $cover = app(PublishedTourCatalog::class)->browse(null, null, '', 'featured', 1)['tours'][0]['cover_image'];
        self::assertNotNull($cover);
        self::assertStringEndsWith('/tours/good.webp', $cover['src']);
    }

    public function test_private_disk_never_receives_a_browser_url(): void
    {
        Storage::fake('public');
        [$tour] = $this->readyTour('p3f2b2-private');
        $this->attach((int) $tour->id, 'private/secret.webp', ['disk' => 'local']);

        $this->get('/tours')->assertOk()->assertInertia(fn ($page) => $page
            ->has('tours', 1)->where('tours.0.cover_image', null)
            ->missing('tours.0.media_url')->missing('tours.0.media'));
    }

    public function test_invalid_path_and_disallowed_svg_never_render_as_cover_images(): void
    {
        Storage::fake('public');
        [$tour] = $this->readyTour('p3f2b2-malformed');
        $this->attach((int) $tour->id, '../secrets.webp', ['is_featured' => true]);
        $this->attach((int) $tour->id, 'tours/active.svg', [
            'mime_type' => 'image/svg+xml', 'sort_order' => 1,
        ]);

        $this->get('/tours')->assertOk()->assertInertia(fn ($page) => $page
            ->where('tours.0.cover_image', null));
    }

    public function test_no_public_storage_object_yields_null_and_a_safe_ui_fallback(): void
    {
        Storage::fake('public');
        [$tour] = $this->readyTour('p3f2b2-empty');
        $this->attach((int) $tour->id, 'tours/unavailable.webp');
        $this->get('/tours')->assertOk()->assertInertia(fn ($page) => $page
            ->where('tours.0.cover_image', null));
    }

    public function test_draft_and_scheduled_tours_do_not_leak_photos(): void
    {
        Storage::fake('public');
        [$public] = $this->readyTour('p3f2b2-public');
        [$draft] = $this->readyTour('p3f2b2-draft');
        [$scheduled] = $this->readyTour('p3f2b2-future');
        $draft->update(['status' => 'draft', 'published_at' => null]);
        $scheduled->update(['status' => 'scheduled', 'published_at' => null, 'scheduled_for' => now()->addDays(2)]);
        foreach (['p3f2b2-public', 'p3f2b2-draft', 'p3f2b2-future'] as $slug) {
            Storage::disk('public')->put('tours/'.$slug.'.webp', 'image');
        }
        $this->attach((int) $public->id, 'tours/p3f2b2-public.webp');
        $this->attach((int) $draft->id, 'tours/p3f2b2-draft.webp');
        $this->attach((int) $scheduled->id, 'tours/p3f2b2-future.webp');

        $this->get('/tours')->assertOk()->assertInertia(fn ($page) => $page
            ->has('tours', 1)
            ->where('tours.0.slug', $public->slug)
            ->where('pagination.total', 1));
    }

    public function test_published_destination_label_is_used_but_archived_one_is_hidden(): void
    {
        [$tour] = $this->readyTour('p3f2b2-destination');
        $destination = $tour->destinations()->firstOrFail();

        $before = app(PublishedTourCatalog::class)->browse(null, null, '', 'featured', 1)['tours'];
        self::assertCount(1, $before);
        self::assertSame((string) $destination->name, $before[0]['destination_name']);

        $destination->update(['status' => 'archived']);
        $after = app(PublishedTourCatalog::class)->browse(null, null, '', 'featured', 1)['tours'];
        foreach ($after as $row) {
            self::assertNotSame((string) $destination->name, $row['destination_name']);
        }
        // Tour::publiclyAvailable() may exclude the whole tour after its
        // destination is archived; that is equally safe.
    }

    public function test_media_publication_does_not_change_legacy_tours_contract(): void
    {
        Storage::fake('public');
        [$tour] = $this->readyTour('p3f2b2-legacy');
        Storage::disk('public')->put('tours/legacy.webp', 'image');
        $this->attach((int) $tour->id, 'tours/legacy.webp');
        $result = app(PublishedTourCatalog::class)->tours();
        self::assertSame(['id', 'title', 'slug', 'summary', 'duration_days', 'is_featured'], array_keys($result[0]));
    }

    public function test_public_cards_expose_no_price_seat_or_booking_secrets(): void
    {
        Storage::fake('public');
        [$tour] = $this->readyTour('p3f2b2-secure');
        Storage::disk('public')->put('tours/secure.webp', 'image');
        $this->attach((int) $tour->id, 'tours/secure.webp');

        $this->get('/tours')->assertOk()->assertInertia(fn ($page) => $page
            ->has('tours', 1)
            ->missing('tours.0.price')
            ->missing('tours.0.seats_committed')
            ->missing('tours.0.booking_reference')
            ->missing('tours.0.contact_email_encrypted')
            ->missing('tours.0.cover_image.mime_type')
            ->missing('tours.0.cover_image.uploaded_by')
        );
        $this->get('/tours/'.$tour->slug.'/booking-request')->assertOk()
            ->assertInertia(fn ($page) => $page->component('public/booking-requests/create'));
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }
}
