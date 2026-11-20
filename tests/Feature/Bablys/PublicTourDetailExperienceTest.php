<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Tours\PublishedTourDetail;
use App\Models\Travel\TourDeparture;
use App\Models\Travel\TourItineraryDay;
use App\Models\Travel\TourMedia;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bablys\TourBookingQaTestCase;

/** P3F.3C: combined public tour detail, sharing, and nonbinding UX contract. */
final class PublicTourDetailExperienceTest extends TourBookingQaTestCase
{
    public function test_published_detail_exposes_canonical_and_nonbinding_enquiry_links(): void
    {
        [$tour] = $this->readyTour('p3f3c-canonical');
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->component('public/tours/show')
            ->where('tour.canonical_url', route('public.tours.show', ['slug' => $tour->slug]))
            ->where('tour.enquiry_url', route('booking-requests.create', ['tour' => $tour->slug]))
            ->where('tour.slug', $tour->slug)
            ->missing('tour.id')
            ->missing('tour.price')
            ->missing('tour.remaining_capacity'));
    }

    public function test_tracking_query_parameters_never_enter_the_public_canonical_url(): void
    {
        [$tour] = $this->readyTour('p3f3c-query');
        $this->get('/tours/'.$tour->slug.'?utm_source=private-campaign&ref=tracking')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('tour.canonical_url', route('public.tours.show', ['slug' => $tour->slug])));
    }

    public function test_gallery_itinerary_and_departure_are_integrated_on_a_single_read_only_page(): void
    {
        Storage::fake('public');
        [$tour, $departure] = $this->readyTour('p3f3c-integrated');
        Storage::disk('public')->put('tours/p3f3c-photo.webp', 'safe-image');
        TourMedia::query()->create([
            'tour_id' => $tour->getKey(), 'disk' => 'public', 'path' => 'tours/p3f3c-photo.webp',
            'mime_type' => 'image/webp', 'file_size' => 1000, 'width' => 1200, 'height' => 800,
            'alt_text' => 'Safe coastal tour', 'sort_order' => 0, 'is_featured' => true,
        ]);
        TourItineraryDay::query()->create([
            'tour_id' => $tour->getKey(), 'day_number' => 1, 'title' => 'Coastal morning',
            'location_label' => 'Coxs Bazar', 'description' => 'Meet the group on the beach.',
            'overnight_location' => null, 'meals' => ['Breakfast'], 'highlights' => ['Seaside walk'],
        ]);
        $departure->update([
            'name' => 'Spring coastline', 'status' => 'open',
            'starts_at' => now('UTC')->addDays(45)->format('Y-m-d H:i:s'),
            'booking_closes_at' => now('UTC')->addDays(25)->format('Y-m-d H:i:s'),
            'internal_notes' => 'STAFF-ONLY-NOTES-REDACT',
        ]);

        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->component('public/tours/show')
            ->where('tour.gallery.0.alt', 'Safe coastal tour')
            ->where('tour.cover_image.alt', 'Safe coastal tour')
            ->where('tour.itinerary.0.title', 'Coastal morning')
            ->where('tour.itinerary.0.meals.0', 'Breakfast')
            ->where('tour.upcoming_departures.0.name', 'Spring coastline')
            ->has('tour.categories', 1)
            ->has('tour.destinations', 1)
            ->missing('tour.upcoming_departures.0.internal_notes')
            ->missing('tour.upcoming_departures.0.seats_committed')
            ->missing('tour.upcoming_departures.0.capacity_total')
            ->missing('tour.upcoming_departures.0.id')
            ->missing('tour.gallery.0.path')
            ->missing('tour.booking_reference')
            ->missing('tour.payment_state'));
    }

    public function test_optional_sections_can_be_empty_without_exposing_inventory(): void
    {
        [$tour, $departure] = $this->readyTour('p3f3c-optional');
        $departure->update(['status' => 'closed']);
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->has('tour.gallery', 0)
            ->has('tour.itinerary', 0)
            ->has('tour.upcoming_departures', 0)
            ->where('tour.cover_image', null)
            ->where('tour.canonical_url', route('public.tours.show', ['slug' => $tour->slug])));
    }

    public function test_unpublished_detail_and_its_supplemental_content_remain_inaccessible(): void
    {
        [$tour] = $this->readyTour('p3f3c-hidden');
        TourItineraryDay::query()->create([
            'tour_id' => $tour->getKey(), 'day_number' => 1, 'title' => 'Hidden itinerary',
            'location_label' => null, 'description' => 'Never disclose',
            'overnight_location' => null, 'meals' => [], 'highlights' => [],
        ]);
        $tour->update(['status' => 'draft', 'published_at' => null]);
        $this->get('/tours/'.$tour->slug)->assertNotFound();
        self::assertNull(app(PublishedTourDetail::class)->find((string) $tour->slug));
    }

    public function test_browsing_public_details_does_not_create_bookings_requests_or_seat_allocations(): void
    {
        [$tour] = $this->readyTour('p3f3c-readonly');
        $this->get('/tours/'.$tour->slug)->assertOk();
        $this->get('/tours/'.$tour->slug.'?from=listing')->assertOk();
        $this->get('/tours/'.$tour->slug)->assertOk();
        $this->assertDatabaseCount('travel_tour_booking_requests', 0);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_departure_preview_excludes_operational_metadata(): void
    {
        [$tour, $departure] = $this->readyTour('p3f3c-private');
        $departure->update([
            'name' => 'Public tour date', 'status' => 'open',
            'starts_at' => now('UTC')->addDays(50)->format('Y-m-d H:i:s'),
            'booking_closes_at' => now('UTC')->addDays(20)->format('Y-m-d H:i:s'),
            'meeting_point' => 'STAFF-ONLY-MEETING-POINT',
            'internal_notes' => 'STAFF-ONLY-INTERNAL-NOTES',
        ]);
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->has('tour.upcoming_departures', 1)
            ->where('tour.upcoming_departures.0.name', 'Public tour date')
            ->missing('tour.upcoming_departures.0.code')
            ->missing('tour.upcoming_departures.0.booking_closes_at')
            ->missing('tour.upcoming_departures.0.meeting_point')
            ->missing('tour.upcoming_departures.0.internal_notes')
            ->missing('tour.upcoming_departures.0.capacity_total')
            ->missing('tour.upcoming_departures.0.seats_committed'));
    }
}
