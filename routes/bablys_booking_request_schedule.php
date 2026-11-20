<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Registered by a single require from routes/console.php. Row locks and
// terminal-state rechecks make overlapping/manual runs safe. Cache mutex
// avoids routine overlap; multi-server operators must use shared cache.
Schedule::command('bablys:bookings:expire-requests --limit=500')
    ->everyTenMinutes()
    ->withoutOverlapping(15);
