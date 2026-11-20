<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Travel\TourDeparture;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Bablys\TourQaTestCase;

final class TourDepartureSchemaTest extends TourQaTestCase
{
    public function test_departure_schema_has_required_columns(): void
    {
        self::assertTrue(Schema::hasTable('travel_tour_departures'));
        self::assertTrue(Schema::hasColumns('travel_tour_departures', [
            'id', 'tour_id', 'code', 'name', 'timezone_name', 'starts_at', 'ends_at',
            'booking_closes_at', 'capacity_total', 'seats_committed', 'status',
            'meeting_point', 'internal_notes', 'created_at', 'updated_at',
        ]));
    }

    public function test_committed_seats_default_to_zero(): void
    {
        $departure = $this->departure('zero');
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        self::assertSame('draft', (string) $departure->fresh()->status);
    }

    public function test_same_code_is_unique_per_tour(): void
    {
        $departure = $this->departure('same');
        $this->expectException(QueryException::class);
        TourDeparture::query()->create($this->row((int) $departure->tour_id, 'same'));
    }

    public function test_different_tours_can_reuse_departure_codes(): void
    {
        $a = $this->departure('shared');
        $b = $this->tour('other-departure');
        TourDeparture::query()->create($this->row((int) $b->id, 'shared'));
        self::assertSame(2, TourDeparture::query()->where('code', 'shared')->count());
        self::assertSame(1, TourDeparture::query()->where('tour_id', $a->tour_id)->count());
    }

    public function test_tour_hard_delete_cascades_departures(): void
    {
        $departure = $this->departure('cascade');
        $tour = $departure->tour;
        self::assertNotNull($tour);
        $tour->forceDelete();
        self::assertDatabaseCount('travel_tour_departures', 0);
    }

    public function test_cannot_insert_orphan_departure(): void
    {
        $this->expectException(QueryException::class);
        DB::table('travel_tour_departures')->insert($this->row(99999999, 'orphan'));
    }

    private function departure(string $code): TourDeparture
    {
        $tour = $this->tour('tour-'.$code);
        return TourDeparture::query()->create($this->row((int) $tour->id, $code));
    }

    /** @return array<string,mixed> */
    private function row(int $tourId, string $code): array
    {
        return [
            'tour_id' => $tourId,
            'code' => $code,
            'name' => 'Departure '.$code,
            'timezone_name' => 'Asia/Dhaka',
            'starts_at' => '2030-06-10 02:00:00',
            'capacity_total' => 20,
            'status' => 'draft',
        ];
    }
}
