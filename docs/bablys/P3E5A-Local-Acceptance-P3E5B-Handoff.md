# P3E.5A Local Acceptance and P3E.5B Handoff

## Automated gates

1. Installer `-WhatIf` passes with no writes; installing a second time skips identical files.
2. `php artisan migrate --pretend` and `php artisan migrate` succeed on Bablys DB backup.
3. All focused allocation schema/readiness/manager tests pass on a separate disposable testing database.
4. All pre-existing Bablys tests pass (especially P3E.4A/B departures, P3E.3A/B pricing, P3E.1/2 itinerary).
5. `vendor\bin\phpstan analyse`, `npm.cmd run types:check`, `npm.cmd run build`, and `php artisan bablys:audit:readiness` pass.
6. No new route or public booking button should appear.

## Behavioral acceptance

- Published eligible tour + published category/destination + active valid price + future `open` departure returns `ready_for_internal_allocation=true`, `public_bookable=false`.
- Draft/scheduled/archived tour, unpublished taxonomy, inactive price, expired cutoff, departed/closed/cancelled departure, and insufficient capacity each block commitments.
- Commit 3 seats -> remaining drops by 3; identical reference retry -> no extra decrement or audit; changed request with same reference -> rejection.
- Distinct references cannot exceed capacity; invalid data leaves counter, ledger and audit unchanged.
- Release -> counter decreases exactly once; repeated release no-op; reference can never be reused.
- Release after closure/cutoff works; a released tombstone survives subsequent removal of its departure.
- Role revocation takes effect on next write; no reference key or currency amount appears in audit metadata.

## Manual browser smoke test

There is no new UI. Visit `/admin/tours/{id}/edit` and verify that departure, pricing and itinerary sections retain their saved data and shared success toasts/confirmations. This is a regression check only.

## Do not mark public bookings complete

No customer checkout, payment, holds/expiry, booking model, booking API or public availability endpoint is supplied. P3E.5B must address those, along with genuine two-connection MySQL concurrency testing (not SQLite alone). P3E.6 remains full cross-domain QA.
