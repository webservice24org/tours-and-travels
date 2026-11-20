<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use Tests\Support\Bablys\TourBookingQaTestCase;

/** P3F.5A: only canonical published listing pages may be indexed. */
final class PublicTourSeoMetadataTest extends TourBookingQaTestCase
{
    public function test_default_tour_listing_is_indexable_with_absolute_canonical(): void
    {
        $this->readyTour('p3f5a-default');
        $this->get('/tours')->assertOk()->assertInertia(fn ($page) => $page
            ->component('public/tours/index')
            ->where('seo.canonical_url', route('public.tours.index'))
            ->where('seo.robots', 'index, follow')
            ->where('seo.title', 'Explore Tours | Bablys Tours & Travels')
            ->has('tours', 1));
    }

    public function test_tracking_parameters_do_not_contaminate_canonical_or_metadata(): void
    {
        $this->readyTour('p3f5a-tracking');
        $this->get('/tours?utm_campaign=private-campaign&reference=secret-raw-key')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('seo.canonical_url', route('public.tours.index'))
                ->where('seo.robots', 'index, follow')
                ->missing('seo.reference')
                ->missing('seo.utm_campaign'));
    }

    public function test_search_results_are_noindex_and_canonicalized_to_primary_listing(): void
    {
        [$tour] = $this->readyTour('p3f5a-search');
        $tour->update(['title' => 'Published Heritage Journey']);
        $this->get('/tours?q=Heritage')->assertOk()->assertInertia(fn ($page) => $page
            ->where('filters.q', 'Heritage')
            ->where('seo.robots', 'noindex, follow')
            ->where('seo.canonical_url', route('public.tours.index'))
            ->where('seo.title', 'Explore Tours | Bablys Tours & Travels')
            ->has('tours', 1));
    }

    public function test_category_and_destination_filters_do_not_create_indexable_duplicates(): void
    {
        [$tour] = $this->readyTour('p3f5a-taxonomy');
        $this->get('/tours?category=p3f5a-taxonomy-category&destination=p3f5a-taxonomy-destination')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->has('tours', 1)
                ->where('tours.0.slug', $tour->slug)
                ->where('seo.robots', 'noindex, follow')
                ->where('seo.canonical_url', route('public.tours.index')));
    }

    public function test_nondefault_sort_order_is_noindex_without_changing_results(): void
    {
        $this->readyTour('p3f5a-sorting');
        $this->get('/tours?sort=duration_desc')->assertOk()->assertInertia(fn ($page) => $page
            ->where('filters.sort', 'duration_desc')
            ->where('seo.robots', 'noindex, follow')
            ->where('seo.canonical_url', route('public.tours.index'))
            ->has('tours', 1));
    }

    public function test_unfiltered_second_page_has_unique_self_canonical(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $this->readyTour(sprintf('p3f5a-page-%02d', $i));
        }
        $this->get('/tours?page=2')->assertOk()->assertInertia(fn ($page) => $page
            ->where('seo.robots', 'index, follow')
            ->where('seo.canonical_url', route('public.tours.index').'?page=2')
            ->where('seo.title', 'Explore Tours — Page 2 | Bablys Tours & Travels')
            ->where('pagination.page', 2)
            ->has('tours', 1));
    }

    public function test_filtered_page_number_cannot_inject_search_or_taxonomy_into_canonical(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $this->readyTour(sprintf('p3f5a-filtered-%02d', $i));
        }
        $this->get('/tours?category=p3f5a-filtered-01-category&page=1')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('seo.robots', 'noindex, follow')
                ->where('seo.canonical_url', route('public.tours.index')));
    }

    public function test_invalid_public_filters_remain_rejected(): void
    {
        $this->get('/tours?category=%3Cscript%3E')->assertNotFound();
        $this->get('/tours?page=-1')->assertNotFound();
        $this->get('/tours?sort=custom-sql')->assertNotFound();
    }

    public function test_listing_and_detail_seo_requests_do_not_create_booking_records(): void
    {
        [$tour] = $this->readyTour('p3f5a-nonbinding');
        $this->get('/tours')->assertOk();
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.canonical_url', route('public.tours.show', ['slug' => $tour->slug]))
            ->missing('tour.booking_reference')
            ->missing('tour.seats_committed'));
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_booking_requests', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }
}
