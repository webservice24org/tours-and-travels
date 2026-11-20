<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Travel\Bookings\TourBookingRequestExpiryManager;
use Illuminate\Console\Command;

/** Safe for the scheduler; no reservation/seat/payment side effects. */
final class ExpireTourBookingRequests extends Command
{
    protected $signature = 'bablys:bookings:expire-requests
        {--limit=500 : Maximum requests to inspect, from 1 to 2000}
        {--dry-run : Display due request count without mutating records}';

    protected $description = 'Expire overdue Bablys booking requests and revoke guest tokens (no seat or payment changes).';

    public function handle(TourBookingRequestExpiryManager $expiry): int
    {
        $raw = $this->option('limit');
        if (! is_string($raw) || $raw === '' || ! ctype_digit($raw)
            || strlen($raw) > 4 || (int) $raw < 1 || (int) $raw > 2000) {
            $this->error('Option --limit must be an integer between 1 and 2000.');
            return self::FAILURE;
        }

        $limit = (int) $raw;
        if ((bool) $this->option('dry-run')) {
            $due = $expiry->dueCount();
            $this->line('DRY RUN: '.min($due, $limit).' eligible request(s) would be inspected; '
                .$due.' total due. No records changed.');
            return self::SUCCESS;
        }

        $result = $expiry->expireBatch($limit);
        $this->line(sprintf(
            'Booking request expiry: selected=%d expired=%d skipped=%d verification_timeout=%d request_timeout=%d',
            $result['selected'], $result['expired'], $result['skipped'],
            $result['verification_timeout'], $result['request_timeout']
        ));
        return self::SUCCESS;
    }
}
