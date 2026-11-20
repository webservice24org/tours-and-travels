<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use Tests\Support\Bablys\TourBookingQaTestCase;

final class PublicTourDiscoveryFoundationTest extends TourBookingQaTestCase
{
    public function test_guest_sees_published_tour_without_private_booking_or_price_payload(): void
    {
        [$tour] = $this->readyTour('p3f-listed');
        $this->get('/tours')->assertOk()->assertInertia(fn ($page) => $page
            ->component('public/tours/index')
            ->has('tours', 1)
            ->where('tours.0.slug', $tour->slug)
            ->where('tours.0.title', $tour->title)
            ->where('result_limit', 24)
            ->missing('tours.0.price')
            ->missing('tours.0.availability')
            ->missing('tours.0.seats_committed')
            ->missing('tours.0.contact_email_encrypted')
        );
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_draft_tour_is_not_listed_even_with_valid_public_taxonomy(): void
    {
        [$tour] = $this->readyTour('p3f-hidden');
        $tour->status = 'draft';
        $tour->published_at = null;
        $tour->save();
        $this->get('/tours')->assertOk()->assertInertia(fn ($page) => $page
            ->component('public/tours/index')->has('tours', 0)
        );
    }

    public function test_public_category_and_destination_slug_filters_are_server_side(): void
    {
        [$one] = $this->readyTour('p3f-one');
        $this->readyTour('p3f-two');

        $this->get('/tours?category=p3f-one-category&destination=p3f-one-destination')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->component('public/tours/index')
                ->has('tours', 1)
                ->where('tours.0.slug', $one->slug)
                ->where('filters.category', 'p3f-one-category')
                ->where('filters.destination', 'p3f-one-destination')
            );
        $this->get('/tours?category=p3f-two-category&destination=p3f-one-destination')
            ->assertOk()->assertInertia(fn ($page) => $page->has('tours', 0));
    }

    public function test_filter_input_rejects_arrays_and_invalid_slug_syntax(): void
    {
        $this->get('/tours?category[]=bad')->assertNotFound();
        $this->get('/tours?category=../admin')->assertNotFound();
        $this->get('/tours?destination=UPPERCASE')->assertNotFound();
    }

    public function test_public_listing_does_not_reveal_draft_summary_in_response(): void
    {
        [$hidden] = $this->readyTour('p3f-secret');
        $hidden->fill([
            'status' => 'draft',
            'published_at' => null,
            'summary' => 'PRIVATE-EDITOR-ONLY-TOUR-MESSAGE',
        ])->save();

        $this->get('/tours')->assertOk()->assertDontSee('PRIVATE-EDITOR-ONLY-TOUR-MESSAGE');
    }
}
