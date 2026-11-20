# P3E.5B.1 Local acceptance

**Apply to:** `C:\xampp\htdocs\bablys-travel`. Run `-WhatIf` first, then installer. Back up the live database before `php artisan migrate --pretend` / `php artisan migrate`.

Run these on a disposable test database:

```powershell
php artisan test --filter=TourBookingSchemaTest
php artisan test --filter=TourBookingLifecycleTest
php artisan test --filter=TourSeatAllocationManagerTest
php artisan test
vendor\bin\phpstan analyse
npm.cmd run types:check
npm.cmd run build
php artisan bablys:audit:readiness
```

Validation checklist:

- Migration `travel_tour_bookings` completes; existing tour and seat allocation tables intact.
- A staff-created booking reserves the correct number of seats and creates an immutable server quote snapshot.
- Exact retries do not increment seats or duplicate audit entries.
- Confirmation does not increment seats or imply payment.
- Cancellation (including after cutoff) returns seats once; a cancelled reference cannot be reused.
- Unauthorized actors are rejected. Logs do not contain a booking reference or prices.
- Existing admin tour editor with pricing, itinerary, departures, tags and media remains working in the browser.
- There are **no** new booking routes or UI at this stage; not a customer-facing feature.

If a test or PHPStan fails, capture the first failing class and line. Do not suppress failures or edit tests just to pass.
