<?php

declare(strict_types=1);

namespace App\Models\Travel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable booking-reference ledger (aside from committed -> released).
 * Do not store names, emails, card details or monetary amounts here.
 *
 * @property int $id
 * @property int $tour_id
 * @property int|null $departure_id
 * @property string $departure_code
 * @property string $reference_key
 * @property string $variant_code
 * @property int $adults
 * @property int $children
 * @property int $seats
 * @property string $status
 * @property string $committed_at
 * @property string|null $released_at
 */
final class TourSeatAllocation extends Model
{
    protected $table = 'travel_tour_seat_allocations';

    /** @var list<string> */
    protected $fillable = [
        'tour_id', 'departure_id', 'departure_code', 'reference_key', 'variant_code',
        'adults', 'children', 'seats', 'status', 'committed_at', 'released_at',
    ];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'tour_id' => 'integer',
            'departure_id' => 'integer',
            'adults' => 'integer',
            'children' => 'integer',
            'seats' => 'integer',
        ];
    }

    /** @return BelongsTo<Tour, $this> */
    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class, 'tour_id');
    }

    /** @return BelongsTo<TourDeparture, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(TourDeparture::class, 'departure_id');
    }
}
