<?php

declare(strict_types=1);

namespace App\Domain\Travel\Bookings;

use App\Models\Travel\Tour;
use App\Models\Travel\TourBooking;
use App\Models\Travel\TourBookingRequest;
use App\Models\Travel\TourBookingQuoteVersion;
use App\Models\Travel\TourDeparture;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P3E.5B.3B: owner-approved, server-generated nonbinding quote version.
 * No reservation/payment is created by preview or acceptance.
 * Lock order for all owner operations: TOUR -> REQUEST -> QUOTE.
 * The staff conversion entrypoint MUST call both assertApprovedForConversion()
 * and assertBookingMatchesApproved() in the same transaction as seat allocation.
 */
final class TourBookingQuoteAcceptanceManager
{
    public function __construct(private readonly TourBookingReadiness $readiness)
    {
    }

    /** @return array<string,int|string|bool> */
    public function previewCustomer(User $owner, TourBookingRequest $request): array
    {
        return $this->review($request, static function (TourBookingRequest $locked) use ($owner): void {
            abort_unless($owner->email_verified_at !== null && $locked->customer_id !== null
                && (int) $locked->customer_id === (int) $owner->getKey(), 404);
        });
    }

    /** @return array<string,int|string|bool> */
    public function previewGuest(TourBookingRequest $request, string $accessToken): array
    {
        return $this->review($request, static function (TourBookingRequest $locked) use ($accessToken): void {
            self::assertGuestProof($locked, $accessToken);
        });
    }

    /** @return array<string,int|string|bool> */
    public function acceptCustomer(User $owner, TourBookingRequest $request, int $revision, string $fingerprint): array
    {
        return $this->accept(
            $request,
            $revision,
            $fingerprint,
            'customer',
            (int) $owner->getKey(),
            static function (TourBookingRequest $locked) use ($owner): void {
                abort_unless($owner->email_verified_at !== null && $locked->customer_id !== null
                    && (int) $locked->customer_id === (int) $owner->getKey(), 404);
            }
        );
    }

    /** @return array<string,int|string|bool> */
    public function acceptGuest(TourBookingRequest $request, string $accessToken, int $revision, string $fingerprint): array
    {
        return $this->accept(
            $request,
            $revision,
            $fingerprint,
            'guest',
            null,
            static function (TourBookingRequest $locked) use ($accessToken): void {
                self::assertGuestProof($locked, $accessToken);
            }
        );
    }

    /**
     * Called ONLY after staff already holds TOUR then REQUEST locks, before
     * creating any booking/seat allocation. A changed quote requires a fresh
     * owner acceptance; earlier acceptance never silently follows new pricing.
     */
    public function assertApprovedForConversion(
        TourBookingRequest $lockedRequest,
        Tour $lockedTour,
        TourDeparture $departure,
    ): void {
        $this->assertActive($lockedRequest);
        $quote = $this->authoritativeQuote($lockedRequest, $lockedTour, $departure);
        $fingerprint = $this->fingerprint($lockedRequest, $quote);
        $latest = $this->versions($lockedRequest)->orderByDesc('revision')->lockForUpdate()->first();
        if (
            $latest === null || $latest->accepted_at_utc === null
            || !hash_equals((string) $latest->fingerprint, $fingerprint)
        ) {
            $this->reject('quote', 'Traveler approval of the current quote is required before conversion.');
        }
    }

    /** Compare the *persisted booking* against the approved quote before commit. */
    public function assertBookingMatchesApproved(TourBookingRequest $lockedRequest, TourBooking $booking): void
    {
        $latest = $this->versions($lockedRequest)->orderByDesc('revision')->first();
        if ($latest === null || $latest->accepted_at_utc === null) {
            $this->reject('quote', 'Approved quote is missing.');
        }
        $price = [
            'currency' => (string) $booking->currency,
            'fraction_digits' => (int) $booking->fraction_digits,
            'regular_total_minor' => (int) $booking->regular_total_minor,
            'discount_minor' => (int) $booking->discount_minor,
            'total_minor' => (int) $booking->total_minor,
            'lines' => $booking->price_lines_snapshot,
        ];
        if (!hash_equals((string) $latest->fingerprint, $this->fingerprint($lockedRequest, $price))) {
            // Exception rolls back BOTH booking row and committed seats in outer transaction.
            $this->reject('quote', 'The booking price changed during conversion; a new approval is required.');
        }
    }

    /**
     * @param callable(TourBookingRequest): void $authorize
     * @return array<string, int|string|bool>
     */
    private function review(TourBookingRequest $request, callable $authorize): array
    {
        return DB::transaction(function () use ($request, $authorize): array {
            $tour = Tour::query()->whereKey($request->tour_id)->lockForUpdate()->firstOrFail();
            $locked = TourBookingRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            $authorize($locked);
            $this->assertActive($locked);
            $departure = TourDeparture::query()->whereKey($locked->departure_id)->firstOrFail();
            $version = $this->refreshQuote($locked, $tour, $departure);
            return $this->safeVersion($version);
        }, 3);
    }

    /**
     * @param callable(TourBookingRequest): void $authorize
     * @return array<string, int|string|bool>
     */
    private function accept(
        TourBookingRequest $request,
        int $revision,
        string $fingerprint,
        string $via,
        ?int $customerId,
        callable $authorize,
    ): array {
        return DB::transaction(function () use ($request, $revision, $fingerprint, $via, $customerId, $authorize): array {
            $tour = Tour::query()->whereKey($request->tour_id)->lockForUpdate()->firstOrFail();
            $locked = TourBookingRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            $authorize($locked);
            $this->assertActive($locked);
            $departure = TourDeparture::query()->whereKey($locked->departure_id)->firstOrFail();
            $version = $this->refreshQuote($locked, $tour, $departure);
            if (
                (int) $version->revision !== $revision
                || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
                || !hash_equals((string) $version->fingerprint, $fingerprint)
            ) {
                $this->reject('quote', 'Quote changed. Review the current price and approve it again.');
            }
            if ($version->accepted_at_utc === null) {
                $version->setAttribute('accepted_at_utc', now('UTC')->format('Y-m-d H:i:s'));
                $version->accepted_via = $via;
                $version->setAttribute('accepted_by_customer_id', $customerId);
                $version->save();
            }
            return $this->safeVersion($version);
        }, 3);
    }

    private function assertActive(TourBookingRequest $row): void
    {
        if (
            $row->status !== 'verified' || $row->booking_id !== null
            || $row->expires_at_utc->isPast()
        ) {
            $this->reject('status', 'This request is no longer eligible for quote approval.');
        }
    }

    private static function assertGuestProof(TourBookingRequest $locked, string $token): void
    {
        abort_unless($locked->customer_id === null && $locked->status === 'verified'
            && preg_match('/^[a-f0-9]{64}$/D', $token) === 1
            && $locked->access_token_hash !== null
            && hash_equals((string) $locked->access_token_hash, hash('sha256', $token)), 404);
    }

    /** @return array{currency:string,fraction_digits:int,regular_total_minor:int,discount_minor:int,total_minor:int,lines:array<mixed>} */
    private function authoritativeQuote(TourBookingRequest $row, Tour $tour, TourDeparture $departure): array
    {
        $check = $this->readiness->inspect(
            $tour,
            $departure,
            (string) $row->variant_code,
            (int) $row->adults,
            (int) $row->children,
            now('UTC')
        );
        $quote = $check['quote'];
        if (
            !$check['ready_for_internal_allocation'] || !is_array($quote)
            || !isset(
            $quote['currency'],
            $quote['fraction_digits'],
            $quote['regular_total_minor'],
            $quote['discount_minor'],
            $quote['total_minor'],
            $quote['lines']
        )
            || !is_array($quote['lines'])
        ) {
            $this->reject('departure', 'The departure or selected price is not currently available.');
        }
        return [
            'currency' => (string) $quote['currency'],
            'fraction_digits' => (int) $quote['fraction_digits'],
            'regular_total_minor' => (int) $quote['regular_total_minor'],
            'discount_minor' => (int) $quote['discount_minor'],
            'total_minor' => (int) $quote['total_minor'],
            'lines' => $quote['lines'],
        ];
    }

    /** @param array{currency:string,fraction_digits:int,regular_total_minor:int,discount_minor:int,total_minor:int,lines:array<mixed>} $quote */
    private function fingerprint(TourBookingRequest $request, array $quote): string
    {
        return hash('sha256', json_encode([
            'v' => 1,
            'tour_id' => (int) $request->tour_id,
            'departure_id' => (int) $request->departure_id,
            'variant_code' => (string) $request->variant_code,
            'adults' => (int) $request->adults,
            'children' => (int) $request->children,
            'currency' => $quote['currency'],
            'fraction_digits' => $quote['fraction_digits'],
            'regular_total_minor' => $quote['regular_total_minor'],
            'discount_minor' => $quote['discount_minor'],
            'total_minor' => $quote['total_minor'],
            'lines' => $quote['lines'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return Builder<TourBookingQuoteVersion> */
    private function versions(TourBookingRequest $row): Builder
    {
        return TourBookingQuoteVersion::query()->where('booking_request_id', $row->getKey());
    }

    private function refreshQuote(TourBookingRequest $request, Tour $tour, TourDeparture $departure): TourBookingQuoteVersion
    {
        $quote = $this->authoritativeQuote($request, $tour, $departure);
        $fingerprint = $this->fingerprint($request, $quote);
        $last = $this->versions($request)->orderByDesc('revision')->lockForUpdate()->first();
        if ($last !== null && hash_equals((string) $last->fingerprint, $fingerprint)) {
            return $last;
        }
        return TourBookingQuoteVersion::query()->create([
            'booking_request_id' => (int) $request->getKey(),
            'revision' => $last === null ? 1 : (int) $last->revision + 1,
            'fingerprint' => $fingerprint,
            'currency' => $quote['currency'],
            'fraction_digits' => $quote['fraction_digits'],
            'regular_total_minor' => $quote['regular_total_minor'],
            'discount_minor' => $quote['discount_minor'],
            'total_minor' => $quote['total_minor'],
            'price_lines_snapshot' => $quote['lines'],
            'quoted_at_utc' => now('UTC')->format('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,int|string|bool> */
    private function safeVersion(TourBookingQuoteVersion $row): array
    {
        return [
            'revision' => (int) $row->revision,
            'fingerprint' => (string) $row->fingerprint,
            'currency' => (string) $row->currency,
            'fraction_digits' => (int) $row->fraction_digits,
            // Serialize money as strings to avoid JS precision loss.
            'regular_total_minor' => (string) $row->regular_total_minor,
            'discount_minor' => (string) $row->discount_minor,
            'total_minor' => (string) $row->total_minor,
            'accepted' => $row->accepted_at_utc !== null,
            'nonbinding' => true,
            'inventory_reserved' => false,
        ];
    }

    /** @return never */
    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
