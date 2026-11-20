<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsRole;
use App\Models\Travel\TourDeparture;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Bablys\TourQaTestCase;

final class AdminTourDeparturesUiTest extends TourQaTestCase
{
    public function test_staff_reads_empty_inventory_with_explicit_not_bookable_state(): void
    {
        $tour = $this->tour('empty-departures');
        $this->actingAs($this->staff('content_editor'));
        $this->getJson($this->url((int) $tour->id))
            ->assertOk()->assertJsonPath('data', [])
            ->assertJsonPath('meta.inventory_only', true)
            ->assertJsonPath('meta.public_bookable', false)
            ->assertHeader('Cache-Control');
    }

    public function test_saves_multiple_departures_and_reads_utc_inventory_in_date_order(): void
    {
        $tour = $this->tour('multiple-departures');
        $this->actingAs($this->staff('content_editor'));
        $later = $this->departure('later');
        $later['starts_at'] = '2030-07-10T08:00:00+06:00';
        $later['ends_at'] = null;
        $later['booking_closes_at'] = null;
        $this->putJson($this->url((int) $tour->id), ['departures' => [$later, $this->departure('early')]])
            ->assertOk()->assertJsonPath('data.0.code', 'early')
            ->assertJsonPath('data.0.starts_at', '2030-06-10T02:00:00Z')
            ->assertJsonPath('data.0.remaining_capacity', 25)
            ->assertJsonPath('data.0.public_bookable', false)
            ->assertJsonPath('data.1.code', 'later');
        $this->assertDatabaseCount('travel_tour_departures', 2);
    }

    public function test_repeated_save_preserves_departure_id_and_has_only_one_audit(): void
    {
        $tour = $this->tour('repeat-departures');
        $this->actingAs($this->staff('content_editor'));
        $url = $this->url((int) $tour->id);
        $this->putJson($url, ['departures' => [$this->departure()]])->assertOk();
        $id = (int) TourDeparture::query()->firstOrFail()->id;
        $this->putJson($url, ['departures' => [$this->departure()]])->assertOk();
        self::assertSame($id, (int) TourDeparture::query()->firstOrFail()->id);
        self::assertSame(1, DB::table('cms_audit_events')->where('action', 'tour.departures.changed')->count());
    }

    public function test_updating_capacity_of_committed_departure_retains_seat_count(): void
    {
        $tour = $this->tour('committed-departure-update');
        $this->actingAs($this->staff('content_editor'));
        $url = $this->url((int) $tour->id);
        $this->putJson($url, ['departures' => [$this->departure()]])->assertOk();
        $id = (int) TourDeparture::query()->firstOrFail()->id;
        DB::table('travel_tour_departures')->where('id', $id)->update(['seats_committed' => 7]);
        $modified = $this->departure();
        $modified['capacity_total'] = 40;
        $this->putJson($url, ['departures' => [$modified]])
            ->assertOk()->assertJsonPath('data.0.remaining_capacity', 33)
            ->assertJsonPath('data.0.seats_committed', 7);
        self::assertSame($id, (int) TourDeparture::query()->firstOrFail()->id);
    }

    public function test_empty_replacement_clears_only_uncommitted_departures(): void
    {
        $tour = $this->tour('clear-departures');
        $this->actingAs($this->staff('content_editor'));
        $url = $this->url((int) $tour->id);
        $this->putJson($url, ['departures' => [$this->departure()]])->assertOk();
        $this->putJson($url, ['departures' => []])->assertOk()->assertJsonPath('data', []);
        $this->assertDatabaseCount('travel_tour_departures', 0);
    }

    public function test_cannot_delete_departures_with_committed_seats(): void
    {
        $tour = $this->tour('committed-departure-clear');
        $this->actingAs($this->staff('content_editor'));
        $url = $this->url((int) $tour->id);
        $this->putJson($url, ['departures' => [$this->departure()]])->assertOk();
        DB::table('travel_tour_departures')->update(['seats_committed' => 1]);
        $this->putJson($url, ['departures' => []])->assertUnprocessable();
        $this->assertDatabaseCount('travel_tour_departures', 1);
    }

    public function test_capacity_cannot_be_reduced_below_committed_seats(): void
    {
        $tour = $this->tour('committed-departure-shrink');
        $this->actingAs($this->staff('content_editor'));
        $url = $this->url((int) $tour->id);
        $this->putJson($url, ['departures' => [$this->departure()]])->assertOk();
        DB::table('travel_tour_departures')->update(['seats_committed' => 20]);
        $bad = $this->departure();
        $bad['capacity_total'] = 19;
        $this->putJson($url, ['departures' => [$bad]])->assertUnprocessable();
        $this->assertDatabaseHas('travel_tour_departures', ['capacity_total' => 25, 'seats_committed' => 20]);
    }

    public function test_committed_departure_dates_and_timezone_cannot_change(): void
    {
        $tour = $this->tour('committed-departure-dates');
        $this->actingAs($this->staff('content_editor'));
        $url = $this->url((int) $tour->id);
        $this->putJson($url, ['departures' => [$this->departure()]])->assertOk();
        DB::table('travel_tour_departures')->update(['seats_committed' => 2]);
        $bad = $this->departure();
        $bad['starts_at'] = '2030-06-11T08:00:00+06:00';
        $this->putJson($url, ['departures' => [$bad]])->assertUnprocessable();
        $this->assertDatabaseHas('travel_tour_departures', ['starts_at' => '2030-06-10 02:00:00']);
    }

    public function test_invalid_timezone_offset_is_rejected_with_no_partial_write(): void
    {
        $tour = $this->tour('invalid-departure-timezone');
        $this->actingAs($this->staff('content_editor'));
        $bad = $this->departure();
        $bad['starts_at'] = '2030-06-10T08:00:00+05:00';
        $this->putJson($this->url((int) $tour->id), ['departures' => [$this->departure('valid'), $bad]])
            ->assertUnprocessable()->assertJsonValidationErrors('departures.1.starts_at');
        $this->assertDatabaseCount('travel_tour_departures', 0);
    }

    public function test_client_cannot_set_or_override_seats_committed(): void
    {
        $tour = $this->tour('seats-client-write');
        $this->actingAs($this->staff('content_editor'));
        $bad = $this->departure();
        $bad['seats_committed'] = 999;
        $this->putJson($this->url((int) $tour->id), ['departures' => [$bad]])
            ->assertUnprocessable()->assertJsonValidationErrors('departures.0');
        $this->assertDatabaseCount('travel_tour_departures', 0);
    }

    public function test_guest_and_customer_cannot_read_or_write_departures(): void
    {
        $tour = $this->tour('nonstaff-departures');
        $url = $this->url((int) $tour->id);
        $this->getJson($url)->assertUnauthorized();
        $this->putJson($url, ['departures' => []])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
        $this->getJson($url)->assertForbidden();
        $this->putJson($url, ['departures' => []])->assertForbidden();
    }

    public function test_designer_can_not_change_departures(): void
    {
        $tour = $this->tour('designer-departures');
        $this->actingAs($this->staff('designer'))
            ->putJson($this->url((int) $tour->id), ['departures' => [$this->departure()]])
            ->assertForbidden();
    }

    public function test_editor_cannot_update_published_departures(): void
    {
        $tour = $this->tour('published-departures', ['status' => 'published', 'published_at' => now()]);
        $this->actingAs($this->staff('content_editor'))
            ->putJson($this->url((int) $tour->id), ['departures' => [$this->departure()]])
            ->assertForbidden();
    }

    public function test_administrator_can_update_published_departures(): void
    {
        $tour = $this->tour('admin-published-departures', ['status' => 'published', 'published_at' => now()]);
        $this->actingAs($this->staff('administrator'))
            ->putJson($this->url((int) $tour->id), ['departures' => [$this->departure()]])
            ->assertOk()->assertJsonPath('data.0.code', 'summer-a');
    }

    public function test_revoking_staff_role_blocks_subsequent_mutations(): void
    {
        $tour = $this->tour('revoked-departures');
        $editor = $this->staff('content_editor');
        $url = $this->url((int) $tour->id);
        $this->actingAs($editor)->putJson($url, ['departures' => [$this->departure()]])->assertOk();
        CmsRole::query()->where('key', 'content_editor')->firstOrFail()->users()->detach($editor->getKey());
        $this->putJson($url, ['departures' => []])->assertForbidden();
        $this->assertDatabaseCount('travel_tour_departures', 1);
    }

    public function test_audit_redacts_departure_details_and_committed_counts(): void
    {
        $tour = $this->tour('private-departures');
        $this->actingAs($this->staff('content_editor'));
        $this->putJson($this->url((int) $tour->id), ['departures' => [$this->departure()]])->assertOk();
        $audit = DB::table('cms_audit_events')->where('action', 'tour.departures.changed')->first();
        self::assertNotNull($audit);
        $serialized = (string) json_encode($audit);
        foreach (['2030-06-10', 'Dhaka Airport', 'Staff-only note'] as $private) {
            self::assertStringNotContainsString($private, $serialized);
        }
    }

    public function test_full_page_editor_mounts_departures_with_pricing_and_itinerary_once(): void
    {
        $tour = $this->tour('departure-ui');
        $this->actingAs($this->staff('administrator'));
        $this->get('/admin/tours/'.$tour->id.'/edit')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('admin/tours/editor')->where('tourId', $tour->id)
        );
        $page = (string) file_get_contents(base_path('resources/js/pages/admin/tours/editor.tsx'));
        $panel = (string) file_get_contents(base_path('resources/js/components/admin/tours/TourDepartureEditor.tsx'));
        self::assertSame(1, substr_count($page, '<TourDepartureEditor'));
        self::assertSame(1, substr_count($page, '<TourPricingEditor'));
        self::assertSame(1, substr_count($page, '<TourItineraryEditor'));
        self::assertStringContainsString('lg:col-span-9', $page);
        self::assertStringNotContainsString('<CmsSidebarMenu', $page);
        self::assertStringContainsString('confirmAction(', $panel);
        self::assertStringContainsString('toast.success(', $panel);
        self::assertStringContainsString('type="button"', $panel);
        self::assertStringNotContainsString('body: { departures: payload, seats_committed:', $panel);
    }

    private function url(int $tourId): string
    {
        return '/admin/internal/tours/'.$tourId.'/departures';
    }

    /** @return array<string,mixed> */
    private function departure(string $code = 'summer-a'): array
    {
        return [
            'code' => $code,
            'name' => 'Summer Departure',
            'timezone_name' => 'Asia/Dhaka',
            'starts_at' => '2030-06-10T08:00:00+06:00',
            'ends_at' => '2030-06-12T08:00:00+06:00',
            'booking_closes_at' => '2030-06-09T18:00:00+06:00',
            'capacity_total' => 25,
            'status' => 'open',
            'meeting_point' => 'Dhaka Airport',
            'internal_notes' => 'Staff-only note',
        ];
    }
}
