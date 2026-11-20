<?php

declare(strict_types=1);

namespace App\Models\Travel;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Non-reserving customer/guest booking request. Not a confirmed booking.
 * Encrypted contact data must never appear in public payloads or audit logs.
 *
 * @property int $id
 * @property int $tour_id
 * @property int $departure_id
 * @property int|null $customer_id
 * @property int|null $booking_id
 * @property string $reference_key
 * @property string $status
 * @property string $variant_code
 * @property int $adults
 * @property int $children
 * @property string|null $verification_token_hash
 * @property string|null $access_token_hash
 * @property \Carbon\CarbonImmutable|null $verification_expires_at_utc
 * @property \Carbon\CarbonImmutable $expires_at_utc
 */
final class TourBookingRequest extends Model
{
    protected $table = 'travel_tour_booking_requests';

    /** @var list<string> */
    protected $fillable = [
        'reference_key', 'tour_id', 'departure_id', 'customer_id', 'booking_id',
        'status', 'variant_code', 'adults', 'children',
        'contact_name_encrypted', 'contact_email_encrypted', 'contact_phone_encrypted',
        'contact_email_lookup_hmac', 'verification_token_hash', 'access_token_hash',
        'verification_expires_at_utc', 'expires_at_utc', 'verified_at_utc',
        'withdrawn_at_utc', 'converted_at_utc',
    ];

    /** @var list<string> */
    protected $hidden = [
        'contact_name_encrypted', 'contact_email_encrypted', 'contact_phone_encrypted',
        'contact_email_lookup_hmac', 'verification_token_hash', 'access_token_hash',
    ];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'tour_id' => 'integer', 'departure_id' => 'integer',
            'customer_id' => 'integer', 'booking_id' => 'integer',
            'adults' => 'integer', 'children' => 'integer',
            'verification_expires_at_utc' => 'immutable_datetime',
            'expires_at_utc' => 'immutable_datetime',
            'verified_at_utc' => 'immutable_datetime',
            'withdrawn_at_utc' => 'immutable_datetime',
            'converted_at_utc' => 'immutable_datetime',
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

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<TourBooking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(TourBooking::class, 'booking_id');
    }
}
