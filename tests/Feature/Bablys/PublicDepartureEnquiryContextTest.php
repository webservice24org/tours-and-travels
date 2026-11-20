<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Travel\Tour;
use App\Models\Travel\TourDeparture;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bablys\TourBookingQaTestCase;

/** P3F.4B: public timestamp hint is not an inventory ID, availability or allocation. */
final class PublicDepartureEnquiryContextTest extends TourBookingQaTestCase
{
    private function dateHint(TourDeparture $departure): string
    {
        return str_replace(' ', 'T', (string) $departure->starts_at).'Z';
    }

    private function createUrl(Tour $tour, ?string $hint = null): string
    {
        $base = route('booking-requests.create', ['tour' => $tour->slug]);
        return $hint === null ? $base : $base.'?departure_at='.rawurlencode($hint);
    }

    private function secondDeparture(Tour $tour): TourDeparture
    {
        return TourDeparture::query()->create([
            'tour_id' => (int) $tour->getKey(),
            'code' => 'winter-b',
            'name' => 'Winter departure',
            'timezone_name' => 'Asia/Dhaka',
            'starts_at' => now('UTC')->addMonths(4)->format('Y-m-d H:i:s'),
            'ends_at' => now('UTC')->addMonths(4)->addDays(3)->format('Y-m-d H:i:s'),
            'booking_closes_at' => now('UTC')->addMonths(3)->format('Y-m-d H:i:s'),
            'capacity_total' => 6,
            'status' => 'open',
        ]);
    }

    public function test_public_departure_preview_exposes_dates_not_inventory_ids_or_capacity(): void
    {
        [$tour, $departure] = $this->readyTour('p3f4b-preview');
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->component('public/tours/show')
            ->where('tour.upcoming_departures.0.starts_at_utc', $this->dateHint($departure))
            ->missing('tour.upcoming_departures.0.id')
            ->missing('tour.upcoming_departures.0.code')
            ->missing('tour.upcoming_departures.0.capacity_total')
            ->missing('tour.upcoming_departures.0.seats_committed')
            ->missing('tour.upcoming_departures.0.booking_closes_at'));
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
    }

    public function test_linked_second_departure_is_selected_from_current_tour_options(): void
    {
        [$tour, $first] = $this->readyTour('p3f4b-selected');
        $second = $this->secondDeparture($tour);
        $this->get($this->createUrl($tour, $this->dateHint($second)))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('public/booking-requests/create')
                ->where('nonbinding', true)
                ->where('departure_selection.requested', true)
                ->where('departure_selection.selected_id', (int) $second->getKey())
                ->where('departure_selection.notice', null)
                ->has('departures', 2)
                ->missing('booking')->missing('payment_state'));
        self::assertSame(0, (int) $first->fresh()->seats_committed);
        self::assertSame(0, (int) $second->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_booking_requests', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_plain_enquiry_remains_backward_compatible_and_nonbinding(): void
    {
        [$tour, $departure] = $this->readyTour('p3f4b-plain');
        $this->get($this->createUrl($tour))->assertOk()->assertInertia(fn ($page) => $page
            ->where('departure_selection.requested', false)
            ->where('departure_selection.selected_id', null)
            ->where('departure_selection.notice', null)
            ->has('departures', 1));
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_booking_requests', 0);
    }

    public function test_malformed_timestamp_is_never_reflected_and_never_auto_selects(): void
    {
        [$tour] = $this->readyTour('p3f4b-malformed');
        $this->get($this->createUrl($tour, '2030-01-01T00:00:00Z<script>'))
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('departure_selection.requested', true)
                ->where('departure_selection.selected_id', null)
                ->where('departure_selection.notice', 'That departure is no longer requestable. Please select an available departure.')
                ->missing('departure_selection.raw')
                ->has('departures', 1));
        $this->assertDatabaseCount('travel_tour_booking_requests', 0);
    }

    public function test_array_query_payload_is_safe_and_does_not_auto_select(): void
    {
        [$tour] = $this->readyTour('p3f4b-array');
        $this->get($this->createUrl($tour).'?departure_at%5B%5D=untrusted')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('departure_selection.requested', true)
                ->where('departure_selection.selected_id', null)
                ->missing('departure_selection.raw'));
    }

    public function test_another_tours_date_cannot_select_unrelated_inventory(): void
    {
        [$tour, $own] = $this->readyTour('p3f4b-own');
        [$otherTour, $foreign] = $this->readyTour('p3f4b-other');
        $foreign->update(['starts_at' => now('UTC')->addDays(180)->format('Y-m-d H:i:s')]);
        $this->get($this->createUrl($tour, $this->dateHint($foreign)))
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('tour.slug', (string) $tour->slug)
                ->where('departure_selection.requested', true)
                ->where('departure_selection.selected_id', null)
                ->has('departures', 1));
        self::assertNotSame((int) $own->getKey(), (int) $foreign->getKey());
        self::assertNotSame((int) $tour->getKey(), (int) $otherTour->getKey());
    }

    public function test_closed_booking_deadline_cannot_be_preselected_or_listed(): void
    {
        [$tour, $departure] = $this->readyTour('p3f4b-deadline');
        $hint = $this->dateHint($departure);
        $departure->update(['booking_closes_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s')]);
        $this->get($this->createUrl($tour, $hint))->assertOk()->assertInertia(fn ($page) => $page
            ->has('departures', 0)
            ->where('departure_selection.selected_id', null)
            ->where('departure_selection.requested', true));
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->has('tour.upcoming_departures', 0));
    }

    public function test_sold_out_departure_preview_does_not_preselect_unrequestable_date(): void
    {
        [$tour, $departure] = $this->readyTour('p3f4b-soldout');
        $hint = $this->dateHint($departure);
        DB::table('travel_tour_departures')->where('id', $departure->getKey())
            ->update(['seats_committed' => (int) $departure->capacity_total]);
        $this->get($this->createUrl($tour, $hint))->assertOk()->assertInertia(fn ($page) => $page
            ->has('departures', 0)
            ->where('departure_selection.selected_id', null)
            ->where('departure_selection.requested', true)
            ->where('departure_selection.notice', 'That departure is no longer requestable. Please select an available departure.'));
        self::assertSame((int) $departure->capacity_total, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_booking_requests', 0);
    }

    public function test_closed_departure_is_not_reintroduced_by_a_date_hint(): void
    {
        [$tour, $departure] = $this->readyTour('p3f4b-closed');
        $hint = $this->dateHint($departure);
        $departure->update(['status' => 'closed']);
        $this->get($this->createUrl($tour, $hint))->assertOk()->assertInertia(fn ($page) => $page
            ->where('departure_selection.requested', true)
            ->where('departure_selection.selected_id', null)
            ->has('departures', 0));
    }

    public function test_date_preference_does_not_override_authoritative_nonbinding_quote(): void
    {
        [$tour, $departure] = $this->readyTour('p3f4b-quote');
        $this->get($this->createUrl($tour, $this->dateHint($departure)))->assertOk();
        $this->postJson('/booking-requests/quote', [
            'tour_id' => (int) $tour->getKey(),
            'departure_id' => (int) $departure->getKey(),
            'variant_code' => 'standard-bdt',
            'adults' => 1,
            'children' => 0,
            'total_minor' => 1,
            'currency' => 'USD',
        ])->assertOk()->assertJsonPath('nonbinding', true)
            ->assertJsonPath('inventory_reserved', false)
            ->assertJsonPath('quote.currency', 'BDT');
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_enquiry_context_does_not_revert_owner_scoped_retry_idempotency(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Public/Travel/PublicBookingRequestController.php'));
        self::assertIsString($source);
        self::assertStringContainsString('bablys:booking-request:v2|', $source);
        self::assertStringContainsString('$submissionOwner', $source);
        self::assertStringContainsString('departure_selection', $source);
        self::assertStringNotContainsString('$sessionIdentifier', $source);
    }

    public function test_sidebar_free_pages_keep_nonbinding_date_links_in_source(): void
    {
        $detailSource = file_get_contents(base_path('resources/js/pages/public/tours/show.tsx'));
        $enquirySource = file_get_contents(base_path('resources/js/pages/public/booking-requests/create.tsx'));
        self::assertIsString($detailSource);
        self::assertIsString($enquirySource);
        self::assertStringContainsString('departure_at=', $detailSource);
        self::assertStringContainsString('encodeURIComponent(departure.starts_at_utc)', $detailSource);
        self::assertStringContainsString('PublicSiteLayout', $detailSource);
        self::assertStringContainsString('PublicSiteLayout', $enquirySource);
        self::assertStringContainsString('noDepartureSelected', $enquirySource);
        self::assertStringNotContainsString('AppSidebar', $detailSource.$enquirySource);
    }
}
