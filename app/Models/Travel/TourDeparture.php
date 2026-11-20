<?php

declare(strict_types=1);

namespace App\Models\Travel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Departure staff inventory. Timestamps are UTC SQL DATETIME strings, not
 * timezone-adjusted Carbon casts. The original IANA timezone is retained.
 *
 * @property int $id
 * @property int $tour_id
 * @property string $code
 * @property string $name
 * @property string $timezone_name
 * @property string $starts_at
 * @property string|null $ends_at
 * @property string|null $booking_closes_at
 * @property int $capacity_total
 * @property int $seats_committed
 * @property string $status
 * @property string|null $meeting_point
 * @property string|null $internal_notes
 */
final class TourDeparture extends Model
{
    protected $table = 'travel_tour_departures';

    /** @var list<string> */
    protected $fillable = [
        'tour_id', 'code', 'name', 'timezone_name', 'starts_at', 'ends_at',
        'booking_closes_at', 'capacity_total', 'status', 'meeting_point',
        'internal_notes',
    ];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['tour_id' => 'integer', 'capacity_total' => 'integer', 'seats_committed' => 'integer'];
    }

    /** @return BelongsTo<Tour, $this> */
    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class, 'tour_id');
    }
}
