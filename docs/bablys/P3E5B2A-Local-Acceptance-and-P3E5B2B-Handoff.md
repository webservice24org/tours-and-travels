# P3E.5B.2A local acceptance

## Preflight

- Back up the Bablys MySQL database and confirm P3E.5A and P3E.5B.1 migrations and local test suites remain green.
- Run installer with `-WhatIf` and confirm only new files; it must not modify tour/editor/sidebar/pricing/departure files.
- Run `php artisan migrate --pretend`, then `php artisan migrate`; verify new `travel_tour_booking_requests` table and indexes.

## Automated tests

On an **isolated disposable testing database** (tests use `RefreshDatabase`):

```powershell
php artisan test --filter=TourBookingRequestSchemaTest
php artisan test --filter=TourBookingRequestManagerTest
php artisan test --filter=TourBookingLifecycleTest
php artisan test --filter=TourSeatAllocationManagerTest
php artisan test
vendor\bin\phpstan analyse
npm.cmd run types:check
npm.cmd run build
php artisan bablys:audit:readiness
```

The 23 new tests span 4 schema/privacy checks and 19 service/authorization/conversion cases. Specifically verify no seats are committed on request, guest ownership verification, strong token checking, customer ownership, staff publish permission, idempotent conversion, server price snapshot and no oversell after an intervening booking.

## Browser expectation

**None in this phase.** No public form or new route is installed, so tour/customer pages remain unchanged and no public booking action should appear. The native Laravel `AppSidebar`, Tiptap, shared confirmations/toasts, existing tour editor and staff booking behavior are unaffected.

## P3E.5B.2B next handoff

Secure read/write API and 9/3-style admin review UX; public customer/guest **request** form with verified email delivery, rate limits, consent, nonbinding quote, owner-scoped status/withdrawal; explicit staff conversion with price re-confirmation and safe notice flow. Follow with payment/cancellation/expiry and two-connection MySQL concurrency verification **before** enabling direct reservations.
