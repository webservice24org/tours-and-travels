<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Departures\TourDepartureManager;
use App\Models\Cms\CmsRole;
use App\Models\Travel\TourDeparture;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Bablys\TourQaTestCase;

final class TourDepartureManagerTest extends TourQaTestCase
{
    public function test_staff_can_save_and_read_ordered_departures(): void
    {
        $actor = $this->staff('content_editor');
        $tour = $this->tour('departures-read');
        $late = $this->input('late');
        $late['starts_at'] = '2030-07-10T08:00:00+06:00';
        $late['ends_at'] = null;
        $late['booking_closes_at'] = null;
        $service = app(TourDepartureManager::class);
        $service->replace($actor, $tour, [$late, $this->input('early')]);
        $stored = $service->forStaff($actor, $tour, new DateTimeImmutable('2029-01-01T00:00:00Z'));
        self::assertSame(['early', 'late'], array_column($stored, 'code'));
        self::assertSame('2030-06-10T02:00:00Z', $stored[0]['starts_at']);
        self::assertSame(25, $stored[0]['remaining_capacity']);
        self::assertFalse($stored[0]['public_bookable']);
    }

    public function test_identical_save_preserves_ids_and_audits_only_once(): void
    {
        $actor = $this->staff('content_editor');
        $tour = $this->tour('departures-repeat');
        $service = app(TourDepartureManager::class);
        $service->replace($actor, $tour, [$this->input()]);
        $id = (int) TourDeparture::query()->firstOrFail()->id;
        $service->replace($actor, $tour, [$this->input()]);
        self::assertSame($id, (int) TourDeparture::query()->firstOrFail()->id);
        self::assertSame(1, DB::table('cms_audit_events')->where('action', 'tour.departures.changed')->count());
    }

    public function test_editing_same_code_preserves_id_and_reserved_count(): void
    {
        $actor = $this->staff('content_editor');
        $tour = $this->tour('departures-update');
        $service = app(TourDepartureManager::class);
        $service->replace($actor, $tour, [$this->input()]);
        $id = (int) TourDeparture::query()->firstOrFail()->id;
        DB::table('travel_tour_departures')->where('id', $id)->update(['seats_committed' => 3]);
        $updated = $this->input();
        $updated['capacity_total'] = 30;
        $updated['name'] = 'Updated date label';
        $service->replace($actor, $tour, [$updated]);
        $row = TourDeparture::query()->firstOrFail();
        self::assertSame($id, (int) $row->id);
        self::assertSame(3, (int) $row->seats_committed);
        self::assertSame(30, (int) $row->capacity_total);
    }

    public function test_invalid_payload_rolls_back_all_rows_and_audit(): void
    {
        $actor = $this->staff('content_editor');
        $tour = $this->tour('departures-rollback');
        $service = app(TourDepartureManager::class);
        $service->replace($actor, $tour, [$this->input()]);
        $before = DB::table('cms_audit_events')->count();
        $bad = $this->input();
        $bad['capacity_total'] = 4.5;
        try {
            $service->replace($actor, $tour, [$bad]);
            self::fail('Invalid capacity must fail.');
        } catch (ValidationException) {
            self::assertSame(25, (int) TourDeparture::query()->firstOrFail()->capacity_total);
            self::assertSame($before, DB::table('cms_audit_events')->count());
        }
    }

    public function test_cannot_shrink_capacity_below_committed_seats(): void
    {
        $actor = $this->staff('content_editor');
        $tour = $this->tour('departures-shrink');
        $service = app(TourDepartureManager::class);
        $service->replace($actor, $tour, [$this->input()]);
        DB::table('travel_tour_departures')->update(['seats_committed' => 20]);
        $changed = $this->input();
        $changed['capacity_total'] = 19;
        $this->expectException(ValidationException::class);
        $service->replace($actor, $tour, [$changed]);
    }

    public function test_cannot_delete_departure_with_committed_seats(): void
    {
        $actor = $this->staff('content_editor');
        $tour = $this->tour('departures-delete-protect');
        $service = app(TourDepartureManager::class);
        $service->replace($actor, $tour, [$this->input()]);
        DB::table('travel_tour_departures')->update(['seats_committed' => 1]);
        $this->expectException(ValidationException::class);
        $service->replace($actor, $tour, []);
    }

    public function test_cannot_reschedule_departure_with_committed_seats(): void
    {
        $actor = $this->staff('content_editor');
        $tour = $this->tour('departures-dates-protect');
        $service = app(TourDepartureManager::class);
        $service->replace($actor, $tour, [$this->input()]);
        DB::table('travel_tour_departures')->update(['seats_committed' => 1]);
        $changed = $this->input();
        $changed['starts_at'] = '2030-06-11T08:00:00+06:00';
        $this->expectException(ValidationException::class);
        $service->replace($actor, $tour, [$changed]);
    }

    public function test_empty_list_removes_uncommitted_departures(): void
    {
        $actor = $this->staff('content_editor');
        $tour = $this->tour('departures-clear');
        $service = app(TourDepartureManager::class);
        $service->replace($actor, $tour, [$this->input()]);
        $service->replace($actor, $tour, []);
        self::assertDatabaseCount('travel_tour_departures', 0);
    }

    public function test_editor_cannot_change_published_tour_departures(): void
    {
        $actor = $this->staff('content_editor');
        $tour = $this->tour('departures-live', ['status' => 'published', 'published_at' => now()]);
        $this->expectException(HttpException::class);
        app(TourDepartureManager::class)->replace($actor, $tour, [$this->input()]);
    }

    public function test_administrator_can_manage_published_departures(): void
    {
        $actor = $this->staff('administrator');
        $tour = $this->tour('departures-live-admin', ['status' => 'published', 'published_at' => now()]);
        app(TourDepartureManager::class)->replace($actor, $tour, [$this->input()]);
        self::assertDatabaseCount('travel_tour_departures', 1);
    }

    public function test_revoking_permission_is_effective_on_next_write(): void
    {
        $actor = $this->staff('content_editor');
        $tour = $this->tour('departures-revoked');
        CmsRole::query()->where('key', 'content_editor')->firstOrFail()->users()->detach($actor->getKey());
        $this->expectException(HttpException::class);
        app(TourDepartureManager::class)->replace($actor, $tour, [$this->input()]);
    }

    public function test_customer_cannot_read_internal_inventory(): void
    {
        $actor = \App\Models\User::factory()->create();
        $tour = $this->tour('departures-customer');
        $this->expectException(HttpException::class);
        app(TourDepartureManager::class)->forStaff($actor, $tour, new DateTimeImmutable('2029-01-01T00:00:00Z'));
    }

    public function test_audit_redacts_dates_notes_meeting_point_and_capacity(): void
    {
        $actor = $this->staff('content_editor');
        $tour = $this->tour('departures-audit');
        app(TourDepartureManager::class)->replace($actor, $tour, [$this->input()]);
        $audit = DB::table('cms_audit_events')->where('action', 'tour.departures.changed')->first();
        self::assertNotNull($audit);
        $content = json_encode($audit);
        self::assertIsString($content);
        foreach (['2030-06-10', 'Dhaka Airport', 'Staff-only note'] as $secret) {
            self::assertStringNotContainsString($secret, $content);
        }
    }

    /** @return array<string,mixed> */
    private function input(string $code = 'summer-a'): array
    {
        return [
            'code' => $code,
            'name' => 'Summer Departure',
            'timezone_name' => 'Asia/Dhaka',
            'starts_at' => '2030-06-10T08:00:00+06:00',
            'ends_at' => '2030-06-12T08:00:00+06:00',
            'booking_closes_at' => '2030-06-09T18:00:00+06:00',
            'capacity_total' => 25, 'status' => 'open',
            'meeting_point' => 'Dhaka Airport',
            'internal_notes' => 'Staff-only note',
        ];
    }
}
