<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Existing deadlines (if any) remain enforceable even when config is disabled.
// Never automatically cancel an actual booking or release committed seats.
Schedule::command('bablys:bookings:flag-overdue --limit=500')
    ->everyTenMinutes()
    ->withoutOverlapping(15);
