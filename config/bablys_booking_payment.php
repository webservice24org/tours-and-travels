<?php

declare(strict_types=1);

/**
 * P3E.5B.3C: deliberately OFF until owner approves the payment and timeout rules.
 * Changes to these operational controls require QA + change-control sign-off.
 */
return [
    'timeout_enabled' => (bool) env('BABLYS_BOOKING_TIMEOUT_ENABLED', false),
    'pending_confirmation_hours' => (int) env('BABLYS_BOOKING_CONFIRMATION_HOURS', 48),
    'manual_reconciliation_enabled' => (bool) env('BABLYS_MANUAL_PAYMENT_RECONCILIATION_ENABLED', false),
    // There is NO code path for automatic booking cancellation or refunds.
];
