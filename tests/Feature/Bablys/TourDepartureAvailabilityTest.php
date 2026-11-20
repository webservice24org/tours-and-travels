<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Departures\TourDepartureAvailability;
use App\Models\Travel\TourDeparture;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TourDepartureAvailabilityTest extends TestCase
{
    private TourDepartureAvailability $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TourDepartureAvailability();
    }

    public function test_open_future_departure_reports_remaining_but_not_bookable(): void
    {
        $item = $this->row('open', 20, 3);
        $info = $this->service->snapshot($item, new DateTimeImmutable('2029-01-01T00:00:00Z'));
        self::assertSame(17, $info['remaining_capacity']);
        self::assertSame('capacity_remaining', $info['capacity_state']);
        self::assertFalse($info['public_bookable']);
    }

    public function test_full_inventory_reports_full(): void
    {
        $info = $this->service->snapshot($this->row('open', 20, 20), new DateTimeImmutable('2029-01-01T00:00:00Z'));
        self::assertSame('full', $info['capacity_state']);
    }

    public function test_departed_tour_never_appears_open(): void
    {
        $info = $this->service->snapshot($this->row('open', 20, 0), new DateTimeImmutable('2031-01-01T00:00:00Z'));
        self::assertSame('departed', $info['capacity_state']);
    }

    public function test_booking_cutoff_closes_inventory_snapshot(): void
    {
        $item = $this->row('open', 20, 0);
        $item->booking_closes_at = '2029-12-01 00:00:00';
        $info = $this->service->snapshot($item, new DateTimeImmutable('2029-12-02T00:00:00Z'));
        self::assertSame('cutoff', $info['capacity_state']);
    }

    public function test_closed_and_cancelled_states_override_capacity(): void
    {
        foreach (['closed', 'cancelled', 'draft'] as $status) {
            $info = $this->service->snapshot($this->row($status, 20, 0), new DateTimeImmutable('2029-01-01T00:00:00Z'));
            self::assertSame($status, $info['capacity_state']);
            self::assertFalse($info['public_bookable']);
        }
    }

    /** Create an unsaved model for a pure snapshot test. */
    private function row(string $status, int $total, int $used): TourDeparture
    {
        $row = new TourDeparture();
        $row->forceFill([
            'status' => $status,
            'starts_at' => '2030-06-10 02:00:00',
            'booking_closes_at' => null,
            'capacity_total' => $total,
            'seats_committed' => $used,
        ]);
        return $row;
    }
}
