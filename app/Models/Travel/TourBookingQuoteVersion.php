<?php

declare(strict_types=1);

namespace App\Models\Travel;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only quote pricing revisions. Acceptance fields set once under locks.
 * No raw traveler identity, access secrets or credit card data stored here.
 *
 * @property int $id
 * @property int $booking_request_id
 * @property int $revision
 * @property string $fingerprint
 * @property string $currency
 * @property int $fraction_digits
 * @property int $regular_total_minor
 * @property int $discount_minor
 * @property int $total_minor
 * @property array<int,array<string,mixed>> $price_lines_snapshot
 * @property CarbonImmutable $quoted_at_utc
 * @property CarbonImmutable|null $accepted_at_utc
 * @property string|null $accepted_via
 * @property int|null $accepted_by_customer_id
 */
final class TourBookingQuoteVersion extends Model
{
    protected $table = 'travel_booking_request_quote_versions';

    /** @var list<string> */
    protected $fillable = [
        'booking_request_id', 'revision', 'fingerprint', 'currency', 'fraction_digits',
        'regular_total_minor', 'discount_minor', 'total_minor', 'price_lines_snapshot',
        'quoted_at_utc', 'accepted_at_utc', 'accepted_via', 'accepted_by_customer_id',
    ];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'booking_request_id' => 'integer', 'revision' => 'integer',
            'fraction_digits' => 'integer', 'regular_total_minor' => 'integer',
            'discount_minor' => 'integer', 'total_minor' => 'integer',
            'price_lines_snapshot' => 'array',
            'quoted_at_utc' => 'immutable_datetime',
            'accepted_at_utc' => 'immutable_datetime',
            'accepted_by_customer_id' => 'integer',
        ];
    }

    /** @return BelongsTo<TourBookingRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(TourBookingRequest::class, 'booking_request_id');
    }
}
