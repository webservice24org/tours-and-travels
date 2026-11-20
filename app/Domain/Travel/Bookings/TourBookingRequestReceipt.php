<?php

declare(strict_types=1);

namespace App\Domain\Travel\Bookings;

use App\Models\Travel\TourBookingRequest;
use JsonSerializable;
use LogicException;

/**
 * Internal delivery contract only. Never serialize this object to an HTTP response.
 * Guest tokens are returned exactly once so a later verified email adapter can
 * deliver them out of band. The database stores hashes, never raw tokens.
 */
final class TourBookingRequestReceipt implements JsonSerializable
{
    public function __construct(
        public readonly TourBookingRequest $request,
        public readonly ?string $verificationToken,
        public readonly ?string $accessToken,
    ) {}

    /** Prevent an accidental controller JSON response from leaking guest secrets. */
    public function jsonSerialize(): never
    {
        throw new LogicException('Internal booking request receipt must never be JSON serialized.');
    }
}
