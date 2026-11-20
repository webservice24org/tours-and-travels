<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use Tests\Support\Bablys\TourBookingQaTestCase;

final class PublicTourBrowseEnhancementTest extends TourBookingQaTestCase
{
    public function test_keyword_search_matches_title_but_not_other_tours(): void
    {
        [$found] = $this->readyTour('p3f2-coral');
        $this->readyTour('p3f2-forest');
        $found->update(['title' => 'Coral Island Discovery']);

        $this->get('/tours?q=Coral')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->component('public/tours/index')
                ->has('tours', 1)
                ->where('tours.0.slug', $found->slug)
                ->where('filters.q', 'Coral')
                ->where('pagination.total_in_window', 1)
            );
    }

    public function test_search_can_match_published_summary_and_excludes_draft(): void
    {
        [$found] = $this->readyTour('p3f2-summary');
        [$draft] = $this->readyTour('p3f2-private');
        $found->update(['summary' => 'Includes crystal lagoons and coastal villages.']);
        $draft->update(['status' => 'draft', 'published_at' => null,
            'summary' => 'Private crystal itinerary - DO NOT EXPOSE']);

        $this->get('/tours?q=crystal')
            ->assertOk()->assertDontSee('Private crystal itinerary')
            ->assertInertia(fn ($page) => $page
                ->has('tours', 1)->where('tours.0.slug', $found->slug));
    }

    public function test_sort_orders_by_allowed_fields_and_retains_filters(): void
    {
        [$short] = $this->readyTour('p3f2-short');
        [$long] = $this->readyTour('p3f2-long');
        $short->update(['title' => 'Zeta', 'duration_days' => 2, 'is_featured' => false]);
        $long->update(['title' => 'Alpha', 'duration_days' => 9, 'is_featured' => true]);

        $this->get('/tours?sort=duration_asc')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.sort', 'duration_asc')
                ->where('tours.0.slug', $short->slug)
                ->where('tours.1.slug', $long->slug));

        $this->get('/tours?sort=title_asc')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tours.0.slug', $long->slug)
                ->where('tours.1.slug', $short->slug));
    }

    public function test_second_page_is_stable_and_bounded_to_twelve_rows(): void
    {
        for ($index = 1; $index <= 13; $index++) {
            $this->readyTour(sprintf('p3f2-paged-%02d', $index));
        }

        $this->get('/tours?sort=title_asc')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('tours', 12)
                ->where('pagination.page', 1)
                ->where('pagination.per_page', 12)
                ->where('pagination.last_page', 2)
                ->where('pagination.has_next', true));

        $this->get('/tours?sort=title_asc&page=2')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('tours', 1)
                ->where('pagination.page', 2)
                ->where('pagination.total_in_window', 13)
                ->where('pagination.has_next', false)
                ->where('pagination.has_previous', true));
    }

    public function test_invalid_search_sort_and_page_query_inputs_are_rejected(): void
    {
        $this->get('/tours?q[]=injected')->assertNotFound();
        $this->get('/tours?sort=price')->assertNotFound();
        $this->get('/tours?sort[]=featured')->assertNotFound();
        $this->get('/tours?page=-1')->assertNotFound();
        $this->get('/tours?page=0')->assertNotFound();
        $this->get('/tours?page[]=2')->assertNotFound();
        $this->get('/tours?page=99999')->assertNotFound();
    }

    public function test_page_after_last_is_not_found_and_does_not_modify_booking_state(): void
    {
        $this->readyTour('p3f2-only');
        $this->get('/tours?page=2')->assertNotFound();
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_public_props_remain_whitelisted_without_pricing_seats_or_secrets(): void
    {
        $this->readyTour('p3f2-private-fields');
        $this->get('/tours?q=p3f2')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('tours', 1)
                ->missing('tours.0.seats_committed')
                ->missing('tours.0.price')
                ->missing('tours.0.traveler_price_minor')
                ->missing('tours.0.contact_email_encrypted')
                ->missing('tours.0.booking_reference')
            );
    }
}
