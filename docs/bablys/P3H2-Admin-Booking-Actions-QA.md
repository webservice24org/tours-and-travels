# Bablys Tours & Travels — P3H.2 Admin Booking Actions & QA

## Scope and contract

- `/admin/bookings` remains the server-paginated senior-staff dashboard; open an existing booking to confirm or cancel it.
- `/admin/booking-requests` retains the existing staff request review; a *verified, unexpired* request offers a two-step conversion action. This does not indicate its quote is approved: the authoritative conversion service rejects missing/stale traveler approval.
- Three new **POST-only** endpoints: `/admin/bookings/{reference}/confirm`, `/admin/bookings/{reference}/cancel`, and `/admin/booking-requests/{reference}/convert`.
- Require authentication, active verified CMS staff, and **both** `tours.edit` and `tours.publish` for every action. Web CSRF applies.
- Only server-persisted references are accepted; client price, payment, seat and status inputs are ignored.
- Booking lifecycle manager handles idempotent confirmation, cancellation, lock ordering, auditing, and seat release.
- Booking request manager handles approval, verification, expiry, pricing revalidation, conversion, atomic seat allocation, and retry.
- Timeout policy blocks cancellation for paid/exceptional payments. **No refund or gateway operation is available.**
- Converting a verified, owner-approved request produces a `pending_confirmation` booking holding committed seats. Confirming it **does not reserve additional seats** and does not imply payment.
- Bablys admin uses one existing `BablysAdminLayout`; public pages remain sidebar-free and unchanged.

## Install safety

Install only on project `C:\xampp\htdocs\bablys-travel`. Dry run with PowerShell `-WhatIf` first. Installer expects three exact original TSX files; it rejects unexpected local edits, performs add/replace only with backups, and registers the new route include exactly once. No DB migrations, changes to booking domain services, or role seed changes.

## Automated test gate

Use isolated MySQL `bablys_suite_clean_test` with `APP_ENV=testing`, `BABLYS_REAL_MYSQL_RACE` unset; **never** `bablys_travel` or `bablys_race_test`.

1. `php artisan test --filter=AdminBookingActionControlsTest --stop-on-failure`
2. `php artisan test --filter=AdminBookingDashboardReadonlyTest`
3. `php artisan test --filter=BookingFinalLifecycleIntegrationTest`
4. `php artisan test --filter=TourBookingQuoteAcceptanceTest`
5. `php artisan test`

Run in regular development CMD: `vendor\bin\phpstan analyse`; `npm.cmd run types:check`; `npm.cmd run build`.

## Manual browser acceptance

1. Log in as a permitted, verified administrator and visit `/admin/bookings`, then a pending booking. Confirm and verify its status becomes confirmed, seat count stays unchanged and payment status remains uncollected.
2. Cancel another unpaid pending or confirmed booking. Type its **full** reference to enable action. Verify inventory decreases by that booking's traveler count exactly once; the booking remains cancelled on refresh/retry.
3. Attempt to cancel a booking marked paid: the button is absent and the server refuses a direct POST. Do not implement an ad-hoc refund.
4. Visit `/admin/booking-requests`. A verified request can be converted **only** after the traveler has accepted the *current* server quote. After conversion, inspect its new pending booking; confirm it separately.
5. Change an approved request's price before conversion: conversion is blocked pending fresh traveler approval and no seat is taken.
6. Verify a guest/customer, a disabled staff user and staff without either permission cannot operate endpoints. Verify CSRF, no GET mutation, no payment or traveler secret leakage, and no duplicate admin sidebar.
7. Browser keyboard review: button focus, inline confirmation, cancellation reference typing, form errors, responsive widths.
8. Optionally rerun the previously passed independent real MySQL race test **only against** `bablys_race_test`, not in the standard test session.

## Exit gate

All focused tests + full suite, PHPStan, type-check/build, browser status/seat checks and staff security checks pass. Then P3H.2 is ready to close, and P3H.3 adds public confirmed/committed/available seat display using a public-safe DTO and fresh server availability.
