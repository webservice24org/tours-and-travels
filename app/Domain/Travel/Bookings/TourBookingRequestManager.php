<?php

declare(strict_types=1);

namespace App\Domain\Travel\Bookings;

use App\Models\Travel\Tour;
use App\Models\Travel\TourBooking;
use App\Models\Travel\TourBookingRequest;
use App\Models\Travel\TourDeparture;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * P3E.5B.2A: non-reserving, INTERNAL-ONLY booking request workflow.
 *
 * NO HTTP routes, emails or public booking toggle in this package. Future
 * controllers MUST add rate limiting, CSRF, email delivery, verified ownership,
 * anti-spam and per-customer/guest privacy enforcement before exposure.
 *
 * A request does not commit seats or authorize payment. Conversion is a trusted
 * STAFF operation using the already tested P3E.5B.1 lifecycle and P3E.5A ledger.
 */
final class TourBookingRequestManager
{
    public function __construct(
        private readonly TourBookingReadiness $readiness,
        private readonly TourBookingLifecycleManager $bookings,
        private readonly TourBookingQuoteAcceptanceManager $quoteApprovals,
    ) {
    }

    public function createGuest(
        Tour $tour,
        TourDeparture $departure,
        string $variantCode,
        int $adults,
        int $children,
        string $contactName,
        string $contactEmail,
        ?string $contactPhone = null,
    ): TourBookingRequestReceipt {
        $name = $this->cleanName($contactName);
        $email = $this->cleanEmail($contactEmail);
        $phone = $this->cleanPhone($contactPhone);

        return DB::transaction(function () use ($tour, $departure, $variantCode, $adults, $children, $name, $email, $phone): TourBookingRequestReceipt {
            $this->assertReady($tour, $departure, $variantCode, $adults, $children);
            $verificationToken = bin2hex(random_bytes(32));
            $accessToken = bin2hex(random_bytes(32));
            $request = $this->createRow(
                $tour,
                $departure,
                null,
                'awaiting_verification',
                $variantCode,
                $adults,
                $children,
                $name,
                $email,
                $phone,
                hash('sha256', $verificationToken),
                hash('sha256', $accessToken)
            );
            return new TourBookingRequestReceipt($request, $verificationToken, $accessToken);
        }, 3);
    }

    public function createCustomer(
        User $customer,
        Tour $tour,
        TourDeparture $departure,
        string $variantCode,
        int $adults,
        int $children,
        ?string $contactPhone = null,
    ): TourBookingRequestReceipt {
        // A signed-in account with an unverified email cannot bypass guest proof.
        abort_unless($customer->email_verified_at !== null, 403);
        $name = $this->cleanName((string) $customer->name);
        $email = $this->cleanEmail((string) $customer->email);
        $phone = $this->cleanPhone($contactPhone);

        return DB::transaction(function () use ($customer, $tour, $departure, $variantCode, $adults, $children, $name, $email, $phone): TourBookingRequestReceipt {
            $this->assertReady($tour, $departure, $variantCode, $adults, $children);
            $request = $this->createRow(
                $tour,
                $departure,
                (int) $customer->getKey(),
                'verified',
                $variantCode,
                $adults,
                $children,
                $name,
                $email,
                $phone,
                null,
                null
            );
            $request->verified_at_utc = now('UTC')->format('Y-m-d H:i:s');
            $request->save();
            return new TourBookingRequestReceipt($request, null, null);
        }, 3);
    }

    /** Verify a guest's control of the submitted email; no inventory is held. */
    public function verifyGuest(TourBookingRequest $request, string $token): TourBookingRequest
    {
        return DB::transaction(function () use ($request, $token): TourBookingRequest {
            $locked = TourBookingRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            if (
                $locked->customer_id !== null || $locked->status !== 'awaiting_verification'
                || $locked->verification_expires_at_utc === null
                || $locked->verification_expires_at_utc->isPast()
                || $locked->expires_at_utc->isPast()
                || !$this->validToken($locked->verification_token_hash, $token)
            ) {
                $this->reject('verification', 'Verification link is invalid or expired.');
            }
            $locked->status = 'verified';
            $locked->verified_at_utc = now('UTC')->format('Y-m-d H:i:s');
            $locked->verification_token_hash = null; // One-time token is no longer valid.
            $locked->save();
            return $locked;
        }, 3);
    }

    /** A guest must possess the separately issued 256-bit access token. */
    public function withdrawGuest(TourBookingRequest $request, string $accessToken): TourBookingRequest
    {
        return DB::transaction(function () use ($request, $accessToken): TourBookingRequest {
            $locked = TourBookingRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->customer_id !== null || !$this->validToken($locked->access_token_hash, $accessToken)) {
                $this->reject('access', 'Request access was not verified.');
            }
            return $this->withdrawLocked($locked);
        }, 3);
    }

    public function withdrawCustomer(User $customer, TourBookingRequest $request): TourBookingRequest
    {
        return DB::transaction(function () use ($customer, $request): TourBookingRequest {
            $locked = TourBookingRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->customer_id !== null
                && (int) $locked->customer_id === (int) $customer->getKey(), 403);
            return $this->withdrawLocked($locked);
        }, 3);
    }

    /**
     * Staff-only conversion; locks TOUR first, then REQUEST, then existing
     * booking/seat aggregate. Every eligibility and capacity rule is rechecked
     * by TourBookingLifecycleManager inside the same outer DB transaction.
     * Failed conversion retains a verified request with NO seat allocation.
     */
    public function convertVerified(User $staff, TourBookingRequest $request): TourBooking
    {
        return DB::transaction(function () use ($staff, $request): TourBooking {
            abort_unless(Gate::forUser($staff)->allows('tours.edit')
                && Gate::forUser($staff)->allows('tours.publish'), 403);
            $lockedTour = Tour::query()->whereKey($request->tour_id)->lockForUpdate()->firstOrFail();
            $locked = TourBookingRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $locked->tour_id !== (int) $lockedTour->getKey()) {
                throw new LogicException('Booking request/tour mismatch.');
            }
            if ($locked->status === 'converted' && $locked->booking_id !== null) {
                $existing = TourBooking::query()
                    ->whereKey($locked->booking_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ((int) $existing->tour_id !== (int) $lockedTour->getKey()) {
                    throw new LogicException('Booking request points to another tour.');
                }
                return $existing;
            }
            if (
                $locked->status !== 'verified' || $locked->booking_id !== null
                || $locked->expires_at_utc->isPast()
            ) {
                $this->reject('status', 'Request is not verified, has expired, or is no longer convertible.');
            }
            $departure = TourDeparture::query()->whereKey($locked->departure_id)->firstOrFail();
            // Mandatory traveler approval. Do not reserve seats until server price matches.
            $this->quoteApprovals->assertApprovedForConversion($locked, $lockedTour, $departure);
            $booking = $this->bookings->createInternal(
                $staff,
                $lockedTour,
                $departure,
                'BKG-' . $locked->reference_key,
                (string) $locked->variant_code,
                (int) $locked->adults,
                (int) $locked->children,
            );
            // Fail closed even if the underlying booking recalculated after the check;
            // exception rolls back booking and seat counter in the outer transaction.
            $this->quoteApprovals->assertBookingMatchesApproved($locked, $booking);
            $locked->status = 'converted';
            $locked->booking_id = (int) $booking->getKey();
            $locked->converted_at_utc = now('UTC')->format('Y-m-d H:i:s');
            $locked->verification_token_hash = null;
            $locked->access_token_hash = null;
            $locked->save();
            return $booking;
        }, 3);
    }

    private function withdrawLocked(TourBookingRequest $locked): TourBookingRequest
    {
        if ($locked->status === 'withdrawn') {
            return $locked;
        }
        if (!in_array($locked->status, ['awaiting_verification', 'verified'], true)) {
            $this->reject('status', 'Request can no longer be withdrawn; contact staff about an actual booking.');
        }
        $locked->status = 'withdrawn';
        $locked->withdrawn_at_utc = now('UTC')->format('Y-m-d H:i:s');
        $locked->verification_token_hash = null;
        $locked->access_token_hash = null;
        $locked->save();
        return $locked;
    }

    private function assertReady(
        Tour $tour,
        TourDeparture $departure,
        string $variantCode,
        int $adults,
        int $children,
    ): void {
        if ($adults < 1 || $children < 0 || $adults + $children > 1000) {
            $this->reject('travelers', 'Provide valid adult and child traveler counts.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/D', $variantCode) !== 1) {
            $this->reject('variant_code', 'Choose a valid pricing option.');
        }
        $check = $this->readiness->inspect(
            $tour->fresh() ?? $tour,
            $departure->fresh() ?? $departure,
            $variantCode,
            $adults,
            $children,
            now('UTC')
        );
        if (!$check['ready_for_internal_allocation']) {
            $this->reject('departure', 'This departure cannot accept a booking request now.');
        }
        // This DOES NOT turn 'public_bookable' on or reserve the seats.
    }

    private function createRow(
        Tour $tour,
        TourDeparture $departure,
        ?int $customerId,
        string $status,
        string $variantCode,
        int $adults,
        int $children,
        string $name,
        string $email,
        ?string $phone,
        ?string $verificationHash,
        ?string $accessHash,
    ): TourBookingRequest {
        $at = now('UTC');
        return TourBookingRequest::query()->create([
            'reference_key' => 'BRQ-' . bin2hex(random_bytes(16)),
            'tour_id' => (int) $tour->getKey(),
            'departure_id' => (int) $departure->getKey(),
            'customer_id' => $customerId,
            'status' => $status,
            'variant_code' => $variantCode,
            'adults' => $adults,
            'children' => $children,
            'contact_name_encrypted' => Crypt::encryptString($name),
            'contact_email_encrypted' => Crypt::encryptString($email),
            'contact_phone_encrypted' => $phone === null ? null : Crypt::encryptString($phone),
            'contact_email_lookup_hmac' => hash_hmac('sha256', $email, (string) config('app.key')),
            'verification_token_hash' => $verificationHash,
            'access_token_hash' => $accessHash,
            'verification_expires_at_utc' => $verificationHash === null ? null : $at->copy()->addHours(48)->format('Y-m-d H:i:s'),
            'expires_at_utc' => $at->copy()->addDays(7)->format('Y-m-d H:i:s'),
        ]);
    }

    private function cleanName(string $value): string
    {
        $value = trim($value);
        if (
            mb_strlen($value) < 2 || mb_strlen($value) > 120
            || preg_match('/[<>\x00-\x1F\x7F]/u', $value) === 1
        ) {
            $this->reject('contact_name', 'Provide a valid contact name.');
        }
        return $value;
    }

    private function cleanEmail(string $value): string
    {
        $value = strtolower(trim($value));
        if (strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->reject('contact_email', 'Provide a valid email address.');
        }
        return $value;
    }

    private function cleanPhone(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^\+?[0-9 ()-]{7,25}$/D', $value) !== 1) {
            $this->reject('contact_phone', 'Provide a valid phone number.');
        }
        return $value;
    }

    private function validToken(?string $storedHash, string $token): bool
    {
        return $storedHash !== null
            && preg_match('/^[a-f0-9]{64}$/D', $token) === 1
            && hash_equals($storedHash, hash('sha256', $token));
    }

    /** @return never */
    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
