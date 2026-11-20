# P3E.5B.1 — Booking aggregate architecture & next-stage handoff

## Trust boundary

This milestone is **not** a customer booking API. The `TourBookingLifecycleManager` is an internal domain API callable only by users with **both** `tours.edit` and `tours.publish`. No routes are registered, no customer information is stored, no checkout, payment, email confirmation, auto-expiry or refunds are implemented. `TourBookingReadiness::inspect()` intentionally retains `public_bookable: false`.

Do **not** connect frontend forms, a customer endpoint, a job acting as a customer, or untrusted user data to `createInternal` without a separate reviewed booking authorization/orchestration layer.

## Durable booking history

`travel_tour_bookings` references the real tour, departure and seat allocation, but snapshots the tour title, departure code/start/timezone, selected variant, integer-money quote lines, currency and currency fraction digits. A later price or departure-name edit cannot rewrite this historic booking snapshot. Bookings have no PII: an opaque key is not an email/phone/card value. `payment_state` remains `not_collected` until an actual payment domain exists.

The table restricts hard deletion of parent tours and allocations while bookings exist. Departure deletion can null the booking's `departure_id` while preserving historic departure detail. Cancellation never reuses the reference key; the allocation ledger also preserves its release tombstone.

## Lifecycle and inventory

- `createInternal()` — pending_confirmation + not_collected, saves the exact server quote snapshot, commits `adults+children` seats once through P3E.5A, inside the same outer transaction.
- Exact retry — returns the existing pending/confirmed record, no new allocation and no new audit.
- `confirm()` — pending_confirmation -> confirmed; no seat counter change and **no assertion of payment**.
- `cancel()` — pending/confirmed -> cancelled; releases seats once inside the same transaction. No refund is attempted. Cancellation is irreversible and idempotent.
- No reactivation, direct price mutation, direct customer self-service, or booking deletions.
- Every mutation locks the tour first; `TourSeatAllocationManager` retains its own reference/departure locking and capacity conditional update.
- All audit events include booking numeric ID only; reference keys, monetary values and traveler fields are never logged.

## Price timing caveat

The initial quote is taken from the authoritative pricing rules under the locked tour, then the existing seat manager rechecks readiness before reserving. The checkout/payment stage must establish a quote-expiry/price-lock policy, especially at an exact promotion-boundary second, and compare the amount at capture to the committed booking snapshot. This stage does not collect a payment.

## Next P3E.5B.2 (not included)

1. Customer/guest booking ownership, email verification or secure guest-lookup policy, consent and PII retention strategy.
2. Public booking service with a separate capability model, rate limiting, CSRF and anti-enumeration, and no direct exposure of staff-only `TourSeatAllocationManager`.
3. Price acceptance and expiry/hold TTL under an explicit clock policy; queued timeout releases and payment/webhook idempotency.
4. Confirmation only after defined payment/manual approval policy, plus refunds/cancellations with payment provider reconciliation.
5. Actual MySQL/MariaDB two-connection simultaneous allocation test (with non-sqlite DB), crash/retry cases, deadlock retry safety and inventory ledger reconciliation.
6. Secured admin booking UI integrated into the **existing native Laravel AppSidebar**, shared ConfirmDialog and toast. No second sidebar.
7. P3E.6 full integration/security and browser acceptance before changing `public_bookable` to true.

## Operational reminders

Use a disposable testing DB for RefreshDatabase. On production-like data, back up before migrations. Do not run `migrate:fresh`. Keep existing MicroWeb Tiptap, itinerary, pricing and departure editors unchanged.
