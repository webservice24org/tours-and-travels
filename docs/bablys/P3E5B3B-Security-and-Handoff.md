# P3E.5B.3B — Security contract and handoff

## Invariants

1. A booking request remains **nonbinding**, with no seat hold and no monetary charge; `public_bookable` remains `false`.
2. Only authenticated email-verified customer **who owns** the request, or a verified guest with the private 256-bit access secret, can review/accept a quote.
3. Quote source: existing `TourBookingReadiness::inspect()` and `TourPriceCalculator`. Never trust client-supplied `currency`, `total_minor`, `price_lines`, traveler counts or departure changes.
4. Quote acceptance is tied to exact server-created revision+fingerprint, tour, departure, option, travelers, integer-money totals and lines. The public fingerprint alone is **not** an authorization token.
5. A quote version is immutable for price fields. Only acceptance metadata can be set once, idempotently. Later prices create **new** revisions; previously accepted versions remain as audit records.
6. Public quote review/accept endpoints are POST, CSRF-protected by Laravel web middleware, rate-limited, `private, no-store`, with strict request validation. Guest access secrets never appear in response/GET URL or logs.
7. The staff `convertVerified()` entrypoint (not an HTTP route) locks tour then request, verifies active status, owner approval of **latest** current price, calls existing booking/seat transaction, and compares final persisted booking snapshot with the approved quote **inside the same outer transaction**. If mismatch, the transaction rolls back booking/seat writes.
8. Existing staff authorization (`tours.edit` + `tours.publish`) remains mandatory; no customer, guest, normal content editor, or unauthenticated caller may invoke conversion.
9. Quote acceptance may precede booking by days, but staff conversion always checks *present* readiness, schedule, seats and quote. Expiry and withdrawal invalidate acceptance implicitly via request status. Actual booking cancellation/payment continues to use separate trusted lifecycle policy.
10. P3E.5B.3A expiry status is independent: no claim of green until its checks pass.

## Locking / retry policy

- Owner quote review/accept: `TOUR -> REQUEST -> latest QUOTE` locks.
- Staff conversion: `TOUR -> REQUEST -> latest QUOTE -> booking/seat allocation/departure` within a single transaction. Idempotent converted requests return the existing linked booking; never allocate twice.
- P3E.5B.3A expiry sweeper locks `REQUEST` only, and never acquires `TOUR` later.
- Real two-connection MySQL race, deadlock retry, expiry-vs-convert and quote-change-vs-convert tests are required under P3E.5B.3D. SQLite feature tests do not prove row-lock behavior.

## Behavior with outdated price

- Staff converter detects current price no longer matches latest approved fingerprint; blocks conversion without creating seats or booking.
- Customer or verified guest refreshes quote panel; the authoritative service appends a new version and shows it **unapproved**.
- Traveler explicitly approves the new version. Fresh trusted staff conversion can then proceed, subject to availability.
- If pricing changed **during** booking creation, final snapshot verification triggers transaction rollback. No silent repricing is permitted.

## Non-goals / future work

P3E.5B.3C defines approved manual-payment vs online-payment scope, payment states, timeout and refund/reconciliation rules. No live gateway is deployed without approval and separate security/test gates. P3E.5B.3D performs real MySQL concurrent-request tests and staff conversion HTTP policy review; P3E.6 closes application integration/security QA.

## Operational caution

No production rollout until test DB, migrations, PHPStan, TS checks, browser guest verification and customer approval, application cache clearing, and mail transport requirements are verified. Never log PII/access credentials. Keep admin/staff access limited.
