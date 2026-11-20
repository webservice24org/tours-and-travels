<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Tours\PublishedTourCatalog;
use Tests\Support\Bablys\TourBookingQaTestCase;

final class PublicTourSqlPaginationTest extends TourBookingQaTestCase
{
    public function test_sql_pagination_traverses_more_than_catalog_fifty_row_cap(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->readyTour(sprintf('p3f2b-bulk-%02d', $i));
        }

        $this->get('/tours?page=5')->assertOk()->assertInertia(
            fn($page) => $page
                ->component('public/tours/index')
                ->has('tours', 7)
                ->where('pagination.page', 5)
                ->where('pagination.per_page', 12)
                ->where('pagination.last_page', 5)
                ->where('pagination.total', 55)
                ->where('pagination.total_in_window', 55)
                ->where('pagination.bounded', false)
                ->where('pagination.has_next', false)
        );
    }

    public function test_sql_search_finds_matches_beyond_legacy_fifty_row_window(): void
    {
        for ($i = 1; $i <= 52; $i++) {
            $this->readyTour(sprintf('p3f2b-search-%02d', $i));
        }
        [$found] = $this->readyTour('p3f2b-last-needle');
        $found->update(['title' => 'Unique Arctic Lantern Expedition']);

        $this->get('/tours?q=Arctic%20Lantern')->assertOk()->assertInertia(
            fn($page) => $page
                ->has('tours', 1)
                ->where('tours.0.slug', $found->slug)
                ->where('pagination.total', 1)
        );
    }

    public function test_search_treats_percent_and_underscore_as_literal_characters(): void
    {
        [$literal] = $this->readyTour('p3f2b-literal');
        [$ordinary] = $this->readyTour('p3f2b-ordinary');
        $literal->update(['title' => 'Save 10%_on holidays']);
        $ordinary->update(['title' => 'Save 1000 on holidays']);

        $this->get('/tours?q=10%25_')->assertOk()->assertInertia(fn($page) => $page
            ->has('tours', 1)->where('tours.0.slug', $literal->slug));
    }

    public function test_published_taxonomy_suggestions_exclude_drafts_and_archived(): void
    {
        $this->readyTour('p3f2b-visible');
        $this->category('p3f2b-draft-category', 'draft');
        $this->destination('p3f2b-archived-destination', 'archived');

        $this->get('/tours')->assertOk()->assertInertia(
            fn($page) => $page
                ->has('filter_options.categories', 1)
                ->where('filter_options.categories.0.slug', 'p3f2b-visible-category')
                ->has('filter_options.destinations', 1)
                ->where('filter_options.destinations.0.slug', 'p3f2b-visible-destination')
        );
    }

    public function test_category_and_destination_filter_keep_published_association_rules(): void
    {
        [$visible] = $this->readyTour('p3f2b-target');
        $this->readyTour('p3f2b-other');

        $this->get('/tours?category=p3f2b-target-category&destination=p3f2b-target-destination')
            ->assertOk()->assertInertia(fn($page) => $page
                ->has('tours', 1)->where('tours.0.slug', $visible->slug)
                ->where('pagination.total', 1));

        $this->get('/tours?category=p3f2b-other-category&destination=p3f2b-target-destination')
            ->assertOk()->assertInertia(fn($page) => $page->has('tours', 0)->where('pagination.total', 0));
    }

    public function test_draft_or_scheduled_tour_never_appears_in_sql_count_or_search(): void
    {
        [$draft] = $this->readyTour('p3f2b-hidden');
        [$visible] = $this->readyTour('p3f2b-only-live');
        [$scheduled] = $this->readyTour('p3f2b-scheduled');
        $scheduled->update(['status' => 'scheduled', 'published_at' => null, 'scheduled_for' => now()->addDay()]);
        $draft->update(['status' => 'draft', 'published_at' => null, 'title' => 'Confidential Secret']);
        $visible->update(['title' => 'Public Journey']);

        $this->get('/tours')
            ->assertOk()
            ->assertDontSee('Confidential Secret')
            ->assertInertia(fn($page) => $page
                ->has('tours', 1)
                ->where('tours.0.slug', $visible->slug)
                ->where('pagination.total', 1));

        $this->get('/tours?q=Confidential')
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->has('tours', 0)
                ->where('pagination.total', 0));

        $this->get('/tours?q=p3f2b-scheduled')
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->has('tours', 0)
                ->where('pagination.total', 0));
    }

    public function test_page_after_last_returns_404_without_side_effects(): void
    {
        $this->readyTour('p3f2b-one');
        $this->get('/tours?page=2')->assertNotFound();
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_legacy_catalog_tours_method_stays_bounded_and_compatible(): void
    {
        [$first] = $this->readyTour('p3f2b-compat');
        $rows = app(PublishedTourCatalog::class)->tours(limit: 12);
        self::assertCount(1, $rows);
        self::assertSame($first->slug, $rows[0]['slug']);
        self::assertSame(['id', 'title', 'slug', 'summary', 'duration_days', 'is_featured'], array_keys($rows[0]));
    }

    public function test_public_payload_keeps_field_allowlist_without_prices_or_capacity(): void
    {
        $this->readyTour('p3f2b-safe');
        $this->get('/tours')->assertOk()->assertInertia(
            fn($page) => $page
                ->has('tours', 1)
                ->missing('tours.0.price')
                ->missing('tours.0.seats_committed')
                ->missing('tours.0.payment_state')
                ->missing('tours.0.contact_email_encrypted')
                ->missing('tours.0.media_url')
        );
    }
}
