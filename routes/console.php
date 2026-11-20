<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
require __DIR__.'/bablys_booking_timeout_schedule.php';
require __DIR__.'/bablys_booking_request_schedule.php';
