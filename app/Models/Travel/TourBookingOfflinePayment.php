<?php

declare(strict_types=1);

namespace App\Models\Travel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable full offline-payment reconciliation record (NOT a payment capture).
 * Only the application service may insert rows; no public API is provided.
 *
 * @property int $id
 * @property int $booking_id
 * @property string $evidence_reference_hash
 * @property string $currency
 * @property int $amount_minor
 * @property string $method
 */
final class TourBookingOfflinePayment extends Model
{
    protected $table = 'travel_tour_booking_offline_payments';

    /** @var list<string> */
    protected $fillable = [
        'booking_id', 'evidence_reference_hash', 'currency', 'amount_minor',
        'method', 'verified_by', 'verified_at_utc',
    ];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'amount_minor' => 'integer',
            'verified_by' => 'integer',
            'verified_at_utc' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TourBooking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(TourBooking::class, 'booking_id');
    }
}
