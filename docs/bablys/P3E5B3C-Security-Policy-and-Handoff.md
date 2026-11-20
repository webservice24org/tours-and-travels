# P3E.5B.3C — Payment & Actual Booking Timeout Policy

## Project boundary

**Bablys Tours & Travels only.** Laravel 13 + Inertia React TSX, local `C:\xampp\htdocs\bablys-travel`. Does not replace P3E.5B.3A request expiry or P3E.5B.3B owner quote approval. Request expiry (7 days/48h verification) applies to **nonbinding requests**, not booked inventory.

## Mandatory business approval gate

The master roadmap explicitly says online payments, reconciliation, refunds and settlement are **optional, separately approved scope**. This package therefore ships with `timeout_enabled=false` and `manual_reconciliation_enabled=false`. No payment gateway, web checkout, webhook, automatic capture, refund, booking cancellation, or provider retry is registered. `public_bookable` remains false; admin booking request review remains read-only. Contact staff separately for paid booking cancellation/refund; the B1 `cancel` service now **rejects** a booking with `payment_state != not_collected`.

## Booking timeout decision (opt-in only)

When `BABLYS_BOOKING_TIMEOUT_ENABLED=true`, **new** trusted staff-created bookings receive `confirmation_due_at_utc` at creation, based on the validated `BABLYS_BOOKING_CONFIRMATION_HOURS` setting (1–168; sample default 48). Existing bookings remain without a deadline after migration, and setting the feature to disabled in future does not erase or bypass existing deadlines. Deadline is not a payment deadline: it requires staff review before `TourBookingLifecycleManager::confirm`, but confirmation of an otherwise valid, not-collected booking remains legal (legacy behavior).

The scheduled `bablys:bookings:flag-overdue` worker marks pending, unpaid and overdue bookings with `timeout_review_required_at_utc`, without modifying booking status, payment state, allocated seats or guest credentials. It locks TOUR then BOOKING, re-evaluates eligibility, and can safely be re-run. The `--dry-run` command counts records only.

A trusted staff member with BOTH `tours.edit` and `tours.publish` may call `TourBookingTimeoutPolicy::renewAfterReview($actor, $booking, $minutes)` after confirming booking/seat validity and reviewing the customer; it extends the deadline by 15–10080 minutes. There is no HTTP route or staff button yet. The booking's departure must still be in the future. This renews a pending booking only, never a cancelled/confirmed or paid booking. The audit event contains an internal booking ID only. No automatic seat release or cancellation is authorized.

## Optional manually verified full offline payment evidence

`BABLYS_MANUAL_PAYMENT_RECONCILIATION_ENABLED` is false by default. Even when enabled, there is **no HTTP, command, guest or public endpoint** to call this service. The staff-only domain method is for future approved back-office integration **after real external evidence verification**. It does not charge a card, settle a payment or call a bank. It accepts exactly one immutable full amount/currency matching the booking snapshot, checks staff permissions, checks pending timeout, stores the HMAC of an opaque non-PII receipt identifier, inserts exactly one unique DB record, and marks `payment_state` as `paid` inside one transaction. Exact replay returns the prior payment row without duplicate audit. Different second evidence or reuse of evidence on another booking is rejected. Pending/confirmed only; cancelled rejected. Partial payments, refunds, chargebacks, due balances, COD and multi-currency settlement are **unsupported**.

Because cancellation after a paid booking is now rejected, no seat release takes place without approved refund reconciliation rules. Do not enable this flag until operations agree how a verified offline payment is substantiated, how disputes are managed, how staff roles should be separated, and how correction/reversal is audited. The hashed evidence reference cannot by itself establish that money was received.

## Locking / safe operations

- Existing B1 `createInternal`, `confirm` and `cancel` have three minimal guarded additions, all within their original transactions. Tour-first ordering remains intact.
- Offline reconciliation locks parent tour, booking and prior ledger in order. Both `booking_id` and `evidence_reference_hash` are unique, constraining repeats across concurrent operators.
- Overdue worker reads candidates unlocked, then locks tour followed by booking to prevent inverse-order deadlocks with B1, and rechecks under locks.
- All changes either update booking+ledger atomically or roll back on exceptions. MySQL two-connection/process racing remains **P3E.5B.3D**, not established by SQLite tests.
- The scheduler uses a ten-minute schedule with overlap protection. Production needs an operating-system scheduler and shared cache if multiple nodes execute it.

## Pre-deployment decisions still outstanding

1. Owner-approved payment methods/provider and who may verify offline receipts.
2. Payment timing, deposit/partial payment, remaining-due policy, refund/chargeback/dispute policy.
3. Booking confirmation deadline and extension authority; overdue cancellation/seat-release decisions. This pack explicitly does **not** auto-release.
4. Operational alerts and worker/cron readiness, staff review UI, financial audit retention and privacy policy.
5. Genuine two-connection MySQL concurrency tests (P3E.5B.3D), then integrated booking security QA (P3E.6).

## Scope and QA status

PHP file syntax and package manifest verified in the artifact builder; actual app integration, DB migrations, PHPStan, Pest and browser QA require the user's local checkout. This subphase remains **LOCAL QA PENDING**, not complete, until those checks pass. P3E.5B.3B's full local QA was not explicitly confirmed when 3C began; run that suite in addition to the new tests.
