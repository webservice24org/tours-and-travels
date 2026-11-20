<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Travel\TourSeatAllocation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Bablys\TourBookingQaTestCase;

final class TourSeatAllocationSchemaTest extends TourBookingQaTestCase
{
    public function test_allocation_ledger_columns_exist(): void
    {
        self::assertTrue(Schema::hasTable('travel_tour_seat_allocations'));
        self::assertTrue(Schema::hasColumns('travel_tour_seat_allocations', [
            'id', 'tour_id', 'departure_id', 'departure_code', 'reference_key', 'variant_code',
            'adults', 'children', 'seats', 'status', 'committed_at', 'released_at',
            'created_at', 'updated_at',
        ]));
    }

    public function test_global_reference_is_unique_even_between_tours(): void
    {
        [$first, $firstDeparture] = $this->readyTour('schema-first');
        [$other, $secondDeparture] = $this->readyTour('schema-second');
        $this->insert((int) $first->id, (int) $firstDeparture->id, 'BOOK-ref-0001');
        $this->expectException(QueryException::class);
        $this->insert((int) $other->id, (int) $secondDeparture->id, 'BOOK-ref-0001');
    }

    public function test_released_tombstone_survives_departure_deletion(): void
    {
        [$tour, $departure] = $this->readyTour('schema-tombstone');
        $this->insert((int) $tour->id, (int) $departure->id, 'BOOK-ref-0002', 'released');
        $departure->delete();
        $row = TourSeatAllocation::query()->firstOrFail();
        self::assertNull($row->departure_id);
        self::assertSame('released', $row->status);
        self::assertSame('summer-a', $row->departure_code);
    }

    public function test_hard_deleted_tour_cascades_allocation_ledger(): void
    {
        [$tour, $departure] = $this->readyTour('schema-hard-delete');
        $this->insert((int) $tour->id, (int) $departure->id, 'BOOK-ref-0003', 'released');
        $tour->forceDelete();
        self::assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_allocation_cannot_reference_missing_tour(): void
    {
        $this->expectException(QueryException::class);
        $this->insert(99999999, null, 'BOOK-ref-0004');
    }

    private function insert(int $tourId, ?int $departureId, string $reference, string $status = 'committed'): void
    {
        DB::table('travel_tour_seat_allocations')->insert([
            'tour_id' => $tourId,
            'departure_id' => $departureId,
            'departure_code' => 'summer-a',
            'reference_key' => $reference,
            'variant_code' => 'standard-bdt',
            'adults' => 1,
            'children' => 0,
            'seats' => 1,
            'status' => $status,
            'committed_at' => now('UTC')->format('Y-m-d H:i:s'),
            'released_at' => $status === 'released' ? now('UTC')->format('Y-m-d H:i:s') : null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
