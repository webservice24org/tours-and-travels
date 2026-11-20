<?php

declare(strict_types=1);

namespace App\Models\Travel;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P3E.5B.1 staff-created booking record; intentionally contains no traveler PII.
 * Monetary values are server-calculated integer minor units, immutable after create.
 *
 * @property int $id
 * @property int $tour_id
 * @property int|null $departure_id
 * @property int $seat_allocation_id
 * @property string $reference_key
 * @property string $status
 * @property string $payment_state
 * @property string $currency
 * @property int $fraction_digits
 * @property int $adults
 * @property int $children
 * @property int $seats
 * @property int $regular_total_minor
 * @property int $discount_minor
 * @property int $total_minor
 * @property array<int,array<string,mixed>> $price_lines_snapshot
 */
final class TourBooking extends Model
{
    protected $table = 'travel_tour_bookings';

    /** @var list<string> */
    protected $fillable = [
        'tour_id', 'departure_id', 'seat_allocation_id', 'reference_key',
        'status', 'payment_state', 'tour_title_snapshot', 'departure_code_snapshot',
        'departure_starts_at_utc', 'departure_timezone', 'variant_code',
        'currency', 'fraction_digits', 'adults', 'children', 'seats',
        'regular_total_minor', 'discount_minor', 'total_minor', 'price_lines_snapshot',
        'quoted_at_utc', 'confirmed_at', 'cancelled_at', 'created_by', 'cancelled_by',
    ];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'tour_id' => 'integer', 'departure_id' => 'integer',
            'seat_allocation_id' => 'integer', 'fraction_digits' => 'integer',
            'adults' => 'integer', 'children' => 'integer', 'seats' => 'integer',
            'regular_total_minor' => 'integer', 'discount_minor' => 'integer',
            'total_minor' => 'integer', 'price_lines_snapshot' => 'array',
            'departure_starts_at_utc' => 'immutable_datetime',
            'quoted_at_utc' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
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

    /** @return BelongsTo<TourSeatAllocation, $this> */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(TourSeatAllocation::class, 'seat_allocation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
