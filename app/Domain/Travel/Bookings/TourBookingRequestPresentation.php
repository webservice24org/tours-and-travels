<?php

declare(strict_types=1);

namespace App\Domain\Travel\Bookings;

use App\Models\Travel\TourBookingRequest;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

/** Explicit allowlist for visitor/customer/staff response data; never serialize the model. */
final class TourBookingRequestPresentation
{
    /** @return array<string,int|string|bool|null> */
    public function safe(TourBookingRequest $row): array
    {
        return [
            'reference' => (string) $row->reference_key,
            'status' => (string) $row->status,
            'tour_title' => (string) ($row->tour->title ?? 'Tour'),
            'departure_name' => (string) ($row->departure->name ?? 'Departure'),
            'departure_starts_at_utc' => $row->departure?->starts_at === null
                ? null : (string) $row->departure->starts_at,
            'variant_code' => (string) $row->variant_code,
            'adults' => (int) $row->adults,
            'children' => (int) $row->children,
            'expires_at_utc' => $row->expires_at_utc->toIso8601String(),
            'can_withdraw' => in_array($row->status, ['awaiting_verification', 'verified'], true)
                && $row->expires_at_utc->isFuture(),
            'has_booking' => $row->booking_id !== null,
        ];
    }

    /** @return array<string,int|string|bool|null> */
    public function staff(TourBookingRequest $row): array
    {
        return array_merge($this->safe($row), [
            'name' => Crypt::decryptString((string) $row->contact_name_encrypted),
            'email' => Crypt::decryptString((string) $row->contact_email_encrypted),
            'phone' => $row->contact_phone_encrypted === null ? null
                : Crypt::decryptString((string) $row->contact_phone_encrypted),
        ]);
    }

    /** @return array<string,int|string|bool|null> */
    public function guestVerified(string $reference, string $accessToken): array
    {
        abort_unless(preg_match('/^BRQ-[a-f0-9]{32}$/D', $reference) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $accessToken) === 1, 404);
        $row = TourBookingRequest::query()->with(['tour', 'departure'])
            ->where('reference_key', $reference)->firstOrFail();
        abort_unless($row->customer_id === null && $row->status === 'verified'
            && $row->expires_at_utc->isFuture()
            && $row->access_token_hash !== null
            && hash_equals((string) $row->access_token_hash, hash('sha256', $accessToken)), 404);

        return $this->safe($row);
    }

    /** @return list<array<string,int|string|bool|null>> */
    public function customerRows(User $customer): array
    {
        $rows = TourBookingRequest::query()->with(['tour', 'departure'])
            ->where('customer_id', $customer->getKey())->orderByDesc('id')->limit(50)
            ->get()->map(fn (TourBookingRequest $row): array => $this->safe($row))->all();

        return array_values($rows);
    }
}
