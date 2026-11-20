<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Departures\TourDepartureRules;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class TourDepartureRulesTest extends TestCase
{
    private TourDepartureRules $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new TourDepartureRules();
    }

    public function test_accepts_dates_with_correct_local_offset_and_normalizes_utc(): void
    {
        $rows = $this->rules->normalize([$this->valid()]);
        self::assertSame('2030-06-10 02:00:00', $rows[0]['starts_at']);
        self::assertSame('2030-06-12 02:00:00', $rows[0]['ends_at']);
        self::assertSame('2030-06-09 12:00:00', $rows[0]['booking_closes_at']);
    }

    public function test_orders_by_start_time_then_code(): void
    {
        $a = $this->valid();
        $b = $this->valid();
        $b['code'] = 'late';
        $b['starts_at'] = '2030-07-10T08:00:00+06:00';
        $b['ends_at'] = null;
        $b['booking_closes_at'] = null;
        $rows = $this->rules->normalize([$b, $a]);
        self::assertSame(['summer-a', 'late'], array_column($rows, 'code'));
    }

    public function test_rejects_duplicate_code_ignoring_case(): void
    {
        $a = $this->valid();
        $b = $this->valid();
        $b['code'] = strtoupper($a['code']);
        $this->expectException(ValidationException::class);
        $this->rules->normalize([$a, $b]);
    }

    public function test_rejects_floats_as_capacity(): void
    {
        $row = $this->valid();
        $row['capacity_total'] = 10.5;
        $this->expectException(ValidationException::class);
        $this->rules->normalize([$row]);
    }

    public function test_rejects_capacity_above_limit(): void
    {
        $row = $this->valid();
        $row['capacity_total'] = 10001;
        $this->expectException(ValidationException::class);
        $this->rules->normalize([$row]);
    }

    public function test_rejects_ambiguous_unzoned_timestamp(): void
    {
        $row = $this->valid();
        $row['starts_at'] = '2030-06-10T08:00:00';
        $this->expectException(ValidationException::class);
        $this->rules->normalize([$row]);
    }

    public function test_rejects_timezone_offset_mismatch(): void
    {
        $row = $this->valid();
        $row['starts_at'] = '2030-06-10T08:00:00Z';
        $this->expectException(ValidationException::class);
        $this->rules->normalize([$row]);
    }

    public function test_rejects_invalid_calendar_date(): void
    {
        $row = $this->valid();
        $row['starts_at'] = '2030-02-30T08:00:00+06:00';
        $this->expectException(ValidationException::class);
        $this->rules->normalize([$row]);
    }

    public function test_rejects_end_at_or_before_start(): void
    {
        $row = $this->valid();
        $row['ends_at'] = $row['starts_at'];
        $this->expectException(ValidationException::class);
        $this->rules->normalize([$row]);
    }

    public function test_rejects_booking_cutoff_after_departure(): void
    {
        $row = $this->valid();
        $row['booking_closes_at'] = '2030-06-10T09:00:00+06:00';
        $this->expectException(ValidationException::class);
        $this->rules->normalize([$row]);
    }

    public function test_rejects_browser_supplied_committed_seats(): void
    {
        $row = $this->valid();
        $row['seats_committed'] = 1000;
        $this->expectException(ValidationException::class);
        $this->rules->normalize([$row]);
    }

    public function test_rejects_markup_in_meeting_point(): void
    {
        $row = $this->valid();
        $row['meeting_point'] = '<img src=x>';
        $this->expectException(ValidationException::class);
        $this->rules->normalize([$row]);
    }

    public function test_rejects_invalid_status(): void
    {
        $row = $this->valid();
        $row['status'] = 'booked';
        $this->expectException(ValidationException::class);
        $this->rules->normalize([$row]);
    }

    public function test_rejects_more_than_two_hundred_departures(): void
    {
        $this->expectException(ValidationException::class);
        $this->rules->normalize(array_fill(0, 201, $this->valid()));
    }

    public function test_accepts_empty_list_and_nullable_optional_fields(): void
    {
        self::assertSame([], $this->rules->normalize([]));
        $row = $this->valid();
        unset($row['ends_at'], $row['booking_closes_at'], $row['meeting_point'], $row['internal_notes']);
        $normalized = $this->rules->normalize([$row]);
        self::assertNull($normalized[0]['ends_at']);
        self::assertNull($normalized[0]['meeting_point']);
    }

    /** @return array<string,mixed> */
    private function valid(): array
    {
        return [
            'code' => 'summer-a', 'name' => 'Summer Departure A',
            'timezone_name' => 'Asia/Dhaka',
            'starts_at' => '2030-06-10T08:00:00+06:00',
            'ends_at' => '2030-06-12T08:00:00+06:00',
            'booking_closes_at' => '2030-06-09T18:00:00+06:00',
            'capacity_total' => 25, 'status' => 'open',
            'meeting_point' => 'Dhaka Airport', 'internal_notes' => 'Staff-only note',
        ];
    }
}
