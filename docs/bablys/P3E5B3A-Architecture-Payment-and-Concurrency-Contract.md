# Bablys P3E.5B.3A — Request expiry and lifecycle contract

## Supported baseline and boundaries

This extension is built on the prior P3E.5A atomic seat ledger, P3E.5B.1 staff booking records, P3E.5B.2A guest/customer request service and P3E.5B.2B HTTP interfaces. It does **not** contain the entire Laravel application. Confirm the baseline still matches before applying this patch.

**Booking requests** are nonbinding and allocate no seats. **Actual bookings** have committed seat allocations and are a distinct aggregate. This package intentionally acts only on `travel_tour_booking_requests` and **never** automatically cancels a booked seat allocation. Expiry of a pending actual booking, refunding money, or provider payment retries requires a separately approved policy and guarded transaction.

## Transition rules

| State when swept | Due condition, evaluated at UTC cutoff | Outcome |
|---|---|---|
| `awaiting_verification` | `expires_at_utc <= now` | `expired` / `request_timeout` |
| `awaiting_verification` | else `verification_expires_at_utc <= now` | `expired` / `verification_timeout` |
| `verified` | `expires_at_utc <= now` | `expired` / `request_timeout` |
| `verified` | guest verification deadline passed but request deadline not passed | unchanged |
| `withdrawn`, `converted`, `expired` | any date | unchanged |
| Active request with non-null `booking_id` | any date | unchanged (safety interlock; requires reconciliation) |

The original reference, consent, customer association, contact encryption and `submission_key_hash` are preserved. Both `verification_token_hash` and `access_token_hash` are nullified on expiry. Expiry audit fields (`expired_at_utc`, `expired_reason`) are additive. Existing B2B account UI already displays terminal `status` and disables withdrawal for it; the existing guest-management endpoint intentionally requires active verified status, so expired links no longer work.

## Locking and race behavior

Candidate IDs are read without locks and capped at 500 per scheduled invocation (command accepts 1..2000). **Each request** is then fetched inside a transaction with `lockForUpdate()`. The due deadline and status are **rechecked under the row lock** before transitioning. If a concurrent verification/withdrawal/conversion already took place, the sweeper skips rather than overwriting it. Database deadlock retries are enabled (3 attempts).

`TourBookingRequestManager::convertVerified` uses parent-**tour** → request → booking/seat locks, while this sweeper only locks the request row. The sweeper must never start locking a tour after taking the request lock, otherwise it could introduce an inverse-order deadlock. It never manipulates committed allocation balances.

> These properties are visible from code and unit tests; **live parallel-connection MySQL tests have not yet been executed**. Multi-process race acceptance remains a separate P3E.5B.3 gate.

## Scheduling

- A single `require __DIR__.'/bablys_booking_request_schedule.php';` is appended to `routes/console.php`.
- Runs `bablys:bookings:expire-requests --limit=500` every 10 minutes with `withoutOverlapping(15)`.
- Run the standard Laravel scheduler through a reliable operating-system cron / task scheduler; installing this code alone does **not** make the OS invoke it.
- Use persistent shared cache for scheduler mutexes when deploying on multiple nodes; even without a single-run scheduler, DB row locks and state checks protect request records.
- Run `--dry-run` to inspect due counts without changing records; command output contains **aggregate counts only**, never guest emails or tokens.
- Queue/email dispatch is deliberately **not part** of this package. Expired guests must submit a new request; there is no automatic verification resend or reminder yet.

## Payment protection and follow-on gates

1. **P3E.5B.3A (this pack):** non-reserving request expiry and terminal-state safety, no payment actions.
2. **P3E.5B.3B:** customer/guest acceptance of a durable *server-computed quote snapshot*, quote expiry/version and explicit re-approval of changed amounts before staff conversion; trusted conversion uses a verified acceptance token/record within one DB transaction.
3. **P3E.5B.3C:** approved payment policy, reconciliation ledger and staff-only payment state changes; require explicit business decisions for manual vs provider payment, refunds, cancellations, pending-booking timeouts, and irreversible captures. No checkout/gateway should be turned on by default.
4. **P3E.5B.3D:** two independent MySQL connections/processes with conflicting seat allocation, withdrawal, expiry and conversion scenarios, plus simulated provider retries and crash recovery.
5. **P3E.6:** full HTTP, RBAC, CSRF, notification, privacy, retry, scheduler, mobile/browser and launch gate.

**Do not expose `convertVerified` in public/admin HTTP endpoints yet.** The current B2A service can convert verified requests without binding the user to the final amount; do not offer that as an approved-price confirmation flow until 3B has been deployed and reviewed. Do not set `public_bookable=true` merely because request expiry works.
