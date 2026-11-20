<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Travel\TourDeparture;
use App\Models\Travel\TourMedia;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bablys\TourBookingQaTestCase;

/** P3F.5C: HTTP crawler directives and public/booking privacy gates. */
final class PublicTourSeoSecurityClosureTest extends TourBookingQaTestCase
{
    public function test_primary_listing_has_self_canonical_without_noindex_http_header(): void
    {
        [$tour] = $this->readyTour('p3f5c-primary');
        $response = $this->get('/tours');
        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('seo.canonical_url', route('public.tours.index'))
            ->where('seo.robots', 'index, follow')
            ->where('tours.0.slug', (string) $tour->slug));
        self::assertNull($response->headers->get('X-Robots-Tag'));
    }

    public function test_keyword_search_is_noindex_in_http_headers_and_seo_props(): void
    {
        [$tour] = $this->readyTour('p3f5c-search');
        $tour->update(['title' => 'Heritage River Journey']);
        $this->get('/tours?q=Heritage')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, follow')
            ->assertInertia(fn ($page) => $page
                ->where('seo.robots', 'noindex, follow')
                ->where('seo.canonical_url', route('public.tours.index'))
                ->has('tours', 1));
    }

    public function test_category_and_destination_filtered_results_have_http_noindex(): void
    {
        $this->readyTour('p3f5c-filters');
        $this->get('/tours?category=p3f5c-filters-category&destination=p3f5c-filters-destination')
            ->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow')
            ->assertInertia(fn ($page) => $page
                ->where('seo.canonical_url', route('public.tours.index'))
                ->where('seo.robots', 'noindex, follow')
                ->has('tours', 1));
    }

    public function test_alternative_sorting_cannot_generate_indexable_duplicate(): void
    {
        $this->readyTour('p3f5c-sorting');
        $this->get('/tours?sort=title_asc')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, follow')
            ->assertInertia(fn ($page) => $page->where('seo.robots', 'noindex, follow')
                ->where('seo.canonical_url', route('public.tours.index')));
    }

    public function test_numbered_unfiltered_pages_keep_distinct_canonical_without_noindex(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $this->readyTour(sprintf('p3f5c-page-%02d', $i));
        }
        $response = $this->get('/tours?page=2');
        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('seo.robots', 'index, follow')
            ->where('seo.canonical_url', route('public.tours.index').'?page=2')
            ->where('pagination.page', 2)->has('tours', 1));
        self::assertNull($response->headers->get('X-Robots-Tag'));
    }

    public function test_tracking_arguments_cannot_change_seo_canonical_or_robots_policy(): void
    {
        $this->readyTour('p3f5c-tracking');
        $response = $this->get('/tours?utm_campaign=secret-track&booking_reference=private-raw');
        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('seo.canonical_url', route('public.tours.index'))
            ->where('seo.robots', 'index, follow')
            ->missing('seo.utm_campaign')->missing('seo.booking_reference'));
        self::assertNull($response->headers->get('X-Robots-Tag'));
    }

    public function test_untrusted_filter_arrays_and_invalid_sort_are_rejected(): void
    {
        $this->get('/tours?q%5B%5D=private')->assertNotFound();
        $this->get('/tours?sort%5B%5D=featured')->assertNotFound();
        $this->get('/tours?category=%3Cscript%3E')->assertNotFound();
        $this->get('/tours?sort=raw-sql')->assertNotFound();
    }

    public function test_out_of_range_pages_are_not_indexable_content(): void
    {
        $this->readyTour('p3f5c-last-page');
        $this->get('/tours?page=2')->assertNotFound();
        $this->get('/tours?page=0')->assertNotFound();
    }

    public function test_draft_scheduled_and_archived_tours_are_not_in_public_detail_or_search(): void
    {
        [$draft] = $this->readyTour('p3f5c-draft');
        [$scheduled] = $this->readyTour('p3f5c-scheduled');
        [$archived] = $this->readyTour('p3f5c-archived');
        $draft->update(['status' => 'draft', 'published_at' => null, 'title' => 'Secret Draft Tours']);
        $scheduled->update(['status' => 'scheduled', 'published_at' => null, 'scheduled_for' => now()->addDay()]);
        $archived->update(['status' => 'archived', 'published_at' => null]);
        foreach ([$draft, $scheduled, $archived] as $tour) {
            $this->get('/tours/'.$tour->slug)->assertNotFound();
        }
        $this->get('/tours?q=Secret%20Draft')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, follow')
            ->assertInertia(fn ($page) => $page->has('tours', 0)->where('pagination.total', 0));
    }

    public function test_detail_exposes_public_canonical_and_dates_without_inventory_or_private_media(): void
    {
        Storage::fake('public');
        [$tour, $departure] = $this->readyTour('p3f5c-detail-private');
        TourMedia::query()->create([
            'tour_id' => (int) $tour->getKey(), 'disk' => 'local',
            'path' => 'private/never-publish.webp', 'mime_type' => 'image/webp',
            'file_size' => 100, 'width' => 200, 'height' => 150,
            'is_featured' => true, 'sort_order' => 0,
        ]);
        $this->get('/tours/'.$tour->slug.'?utm_campaign=secret-staff')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tour.canonical_url', route('public.tours.show', ['slug' => (string) $tour->slug]))
                ->where('tour.cover_image', null)
                ->missing('tour.id')
                ->missing('tour.internal_notes')
                ->missing('tour.booking_reference')
                ->missing('tour.payment_state')
                ->missing('tour.upcoming_departures.0.id')
                ->missing('tour.upcoming_departures.0.capacity_total')
                ->missing('tour.upcoming_departures.0.seats_committed')
                ->missing('tour.upcoming_departures.0.booking_closes_at')
                ->missing('tour.gallery.0.path'));
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
    }

    public function test_raw_departure_hint_is_not_reflected_or_allowed_to_reserve_seats(): void
    {
        [$tour, $departure] = $this->readyTour('p3f5c-departure');
        $url = '/tours/'.$tour->slug.'/booking-request?departure_at='.rawurlencode('2030-01-01T00:00:00Z<secret>');
        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
            ->where('nonbinding', true)
            ->where('departure_selection.requested', true)
            ->where('departure_selection.selected_id', null)
            ->missing('departure_selection.raw')
            ->missing('booking')
            ->missing('payment_state'));
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_public_quote_ignores_client_totals_and_never_allocates_seats(): void
    {
        [$tour, $departure] = $this->readyTour('p3f5c-quote');
        $this->postJson('/booking-requests/quote', [
            'tour_id' => (int) $tour->getKey(),
            'departure_id' => (int) $departure->getKey(),
            'variant_code' => 'standard-bdt',
            'adults' => 1, 'children' => 0,
            'total_minor' => 1, 'currency' => 'USD',
        ])->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('nonbinding', true)
            ->assertJsonPath('inventory_reserved', false)
            ->assertJsonPath('quote.currency', 'BDT');
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_public_source_preserves_robots_metadata_sidebar_free_layout_and_safe_date_hint(): void
    {
        $paths = [
            'resources/js/layouts/public-site-layout.tsx',
            'resources/js/pages/public/tours/index.tsx',
            'resources/js/pages/public/tours/show.tsx',
            'resources/js/pages/public/booking-requests/create.tsx',
        ];
        foreach ($paths as $path) {
            $source = file_get_contents(base_path($path));
            self::assertIsString($source, 'Missing public source: '.$path);
            self::assertStringNotContainsString('AppSidebar', $source);
            self::assertStringNotContainsString('AppLayout', $source);
        }
        $listing = file_get_contents(base_path('resources/js/pages/public/tours/index.tsx'));
        $detail = file_get_contents(base_path('resources/js/pages/public/tours/show.tsx'));
        $form = file_get_contents(base_path('resources/js/pages/public/booking-requests/create.tsx'));
        self::assertIsString($listing);
        self::assertIsString($detail);
        self::assertIsString($form);
        self::assertStringContainsString('rel="canonical"', $listing);
        self::assertStringContainsString('name="robots"', $listing);
        self::assertStringContainsString('og:url', $detail);
        self::assertStringContainsString('encodeURIComponent(departure.starts_at_utc)', $detail);
        self::assertStringContainsString('noDepartureSelected', $form);
        self::assertStringContainsString('PublicSiteLayout', $form);
    }

    public function test_public_seo_and_detail_get_requests_do_not_create_booking_or_payment_records(): void
    {
        [$tour] = $this->readyTour('p3f5c-no-write');
        $this->get('/tours')->assertOk();
        $this->get('/tours?q=Tour')->assertOk();
        $this->get('/tours/'.$tour->slug)->assertOk();
        $this->get('/tours/'.$tour->slug.'/booking-request')->assertOk();
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_booking_requests', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
        $this->assertDatabaseCount('travel_tour_booking_offline_payments', 0);
    }
}
