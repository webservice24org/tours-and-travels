<?php

declare(strict_types=1);

namespace App\Domain\Travel\Bookings;

use App\Models\Travel\TourBookingRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * P3E.5B.3A: expire NON-RESERVING booking requests only.
 *
 * No payment, booking, departure or seat-allocation table is written here.
 * Candidate discovery is deliberately non-locking; every transition is
 * re-evaluated under a row lock, so withdrawal, verification and conversion
 * may win the race without the sweeper overwriting their terminal state.
 */
final class TourBookingRequestExpiryManager
{
    /** @return array{selected:int,expired:int,skipped:int,verification_timeout:int,request_timeout:int} */
    public function expireBatch(int $limit = 500): array
    {
        $this->assertLimit($limit);
        $cutoff = CarbonImmutable::instance(now('UTC'));
        $identifiers = $this->dueQuery($cutoff)->orderBy('id')->limit($limit)->pluck('id')->all();
        $result = [
            'selected' => count($identifiers),
            'expired' => 0,
            'skipped' => 0,
            'verification_timeout' => 0,
            'request_timeout' => 0,
        ];

        foreach ($identifiers as $identifier) {
            $reason = DB::transaction(function () use ($identifier, $cutoff): ?string {
                $row = TourBookingRequest::query()->whereKey((int) $identifier)
                    ->lockForUpdate()->first();
                if ($row === null || $row->booking_id !== null) {
                    return null;
                }
                $reason = $this->expiryReason($row, $cutoff);
                if ($reason === null) {
                    return null;
                }

                $row->status = 'expired';
                $row->setAttribute('expired_at_utc', $cutoff->format('Y-m-d H:i:s'));
                $row->setAttribute('expired_reason', $reason);
                $row->verification_token_hash = null;
                $row->access_token_hash = null;
                // Preserve the original submission_key_hash for durable idempotency.
                $row->save();

                return $reason;
            }, 3);

            if ($reason === null) {
                $result['skipped']++;
            } else {
                $result['expired']++;
                if ($reason === 'verification_timeout') {
                    $result['verification_timeout']++;
                } else {
                    $result['request_timeout']++;
                }
            }
        }

        return $result;
    }

    /** Count actionable candidates without acquiring locks or making changes. */
    public function dueCount(): int
    {
        return $this->dueQuery(CarbonImmutable::instance(now('UTC')))->count();
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 2000) {
            throw new InvalidArgumentException('Expiry batch size must be between 1 and 2000.');
        }
    }

    private function dueQuery(CarbonImmutable $cutoff): Builder
    {
        $timestamp = $cutoff->format('Y-m-d H:i:s');

        return DB::table('travel_tour_booking_requests')
            ->whereNull('booking_id')
            ->whereIn('status', ['awaiting_verification', 'verified'])
            ->where(static function (Builder $query) use ($timestamp): void {
                $query->where('expires_at_utc', '<=', $timestamp)
                    ->orWhere(static function (Builder $inner) use ($timestamp): void {
                        $inner->where('status', 'awaiting_verification')
                            ->whereNotNull('verification_expires_at_utc')
                            ->where('verification_expires_at_utc', '<=', $timestamp);
                    });
            });
    }

    private function expiryReason(TourBookingRequest $row, CarbonImmutable $cutoff): ?string
    {
        if (! in_array((string) $row->status, ['awaiting_verification', 'verified'], true)) {
            return null;
        }
        // Both database timestamps are persisted as UTC DATETIME (no timezone).
        // Compare their raw, fixed-width UTC values just like the candidate SQL;
        // Eloquent's date cast must not reinterpret them in the app timezone.
        $timestamp = $cutoff->format('Y-m-d H:i:s');
        $expires = $row->getRawOriginal('expires_at_utc');
        $verificationExpires = $row->getRawOriginal('verification_expires_at_utc');
        if (is_string($expires) && $expires <= $timestamp) {
            return 'request_timeout';
        }
        if ((string) $row->status === 'awaiting_verification'
            && is_string($verificationExpires) && $verificationExpires <= $timestamp) {
            return 'verification_timeout';
        }

        return null;
    }
}
