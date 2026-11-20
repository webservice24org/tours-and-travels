<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Travel\Bookings\TourBookingTimeoutPolicy;
use Illuminate\Console\Command;

/** Flags only; never cancels/reopens or changes inventory/payment. */
final class FlagOverdueTourBookings extends Command
{
    protected $signature = 'bablys:bookings:flag-overdue
        {--limit=500 : Maximum booking rows to review (1..2000)}
        {--dry-run : Count due bookings without modifying records}';

    protected $description = 'Flag overdue unpaid pending Bablys bookings for human review; no cancellation or seat release.';

    public function handle(TourBookingTimeoutPolicy $timeouts): int
    {
        $raw = $this->option('limit');
        if (! is_string($raw) || $raw === '' || ! ctype_digit($raw)
            || strlen($raw) > 4 || (int) $raw < 1 || (int) $raw > 2000) {
            $this->error('Option --limit must be an integer between 1 and 2000.');
            return self::FAILURE;
        }
        $limit = (int) $raw;
        if ((bool) $this->option('dry-run')) {
            $due = $timeouts->dueCount();
            $this->line('DRY RUN: '.min($due, $limit).' eligible booking(s) would be inspected; '
                .$due.' total due. No records changed.');
            return self::SUCCESS;
        }
        $outcome = $timeouts->flagOverdueBatch($limit);
        $this->line(sprintf('Booking timeout review: selected=%d flagged=%d skipped=%d; no seats/payment changed.',
            $outcome['selected'], $outcome['flagged'], $outcome['skipped']));
        return self::SUCCESS;
    }
}
