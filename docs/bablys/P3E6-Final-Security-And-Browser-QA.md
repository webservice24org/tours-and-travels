# Bablys P3E.6 — Full Booking Integration & Security QA

Project boundary: **Bablys Tours & Travels only** (`C:\xampp\htdocs\bablys-travel`).

## Scope and existing verified baseline (reported by owner)

- P3E.5B.3D: actual independent-worker MySQL race test **passed: 1 test / 72 assertions** on `bablys_race_test`.
- Clean ordinary full suite **646 passed / 3 expected skips / 3,361 assertions** on a separate clean test database.
- PHPStan **201/201 clean**, TypeScript check and production build passed.
- The app's `TourBookingRequestManager::convertVerified()` uses a locking read (`lockForUpdate()`) when retrieving an already-converted booking; retain this fix.
- Existing business policy remains: booking requests are **nonbinding**; only authorized staff can convert; payment collection and automatic cancellation remain disabled unless formally approved. No gateway/refund activation in this pack.

These numbers describe the *pre-P3E.6* baseline, **not** results for the new tests.

## P3E.6A — Authorization, privacy, request security

Automated `BookingFinalSecurityRegressionTest` checks: guest email verification + separate access proof; wrong access token; cross-customer quote denial; forged price/fingerprint rejection; consent; customer-request list isolation; GET verification cannot mutate state.

Manual checks required before release:

- [ ] Confirm browser POST without valid CSRF token is rejected on staging. Laravel feature tests commonly disable CSRF middleware and therefore cannot prove live CSRF enforcement.
- [ ] Confirm throttling/anti-spam on staging with test accounts; verify 429 responses and no secret tokens in logs.
- [ ] Confirm email verification links are delivered over HTTPS; raw secrets remain in URL **fragment**, not query string, response, server logs or analytics.
- [ ] Audit role matrix for staff and customer; private staff contact data is not shown to public visitors.
- [ ] Confirm cache headers, indexing and reverse-proxy behavior for private pages and authenticated/API responses.
- [ ] Verify that revoked/expired tokens cannot access a request and that consent records remain retained without exposing raw PII.

## P3E.6B — End-to-end booking lifecycle

Automated `BookingFinalLifecycleIntegrationTest` checks: verified customer form -> server quote -> traveler approval -> staff conversion -> idempotent retry -> confirmation; guest verification/quote/approved conversion; changed-price reapproval; expired request no seat commit; cancellation releases seats once; paid booking cancellation rejected; unauthorized conversion rejected.

Manual checks:

- [ ] Staff can review incoming requests but the currently approved **read-only** CRM does not expose a public or staff HTTP conversion action. Conversion is still a trusted internal domain operation until separately approved.
- [ ] Repricing is displayed clearly before any new acceptance; stale approval must not book at a new price.
- [ ] Audit booking/seat status transitions in MySQL and mutation audit logs; no raw guest token, bank reference or contact PII in logs.
- [ ] Verify the offline payment toggle and booking timeout toggle remain disabled by default unless the owner separately approves them.
- [ ] Re-run the independent MySQL race suite separately, on its **own** database, if any booking lock/transaction logic changes.

## P3E.6C — Interface, responsive and accessibility review

Automated `BookingFinalHttpContractTest` checks: Inertia public booking form, guest submission secret-link fragments, safe POST-only quote routes, staff read-only page, private no-store responses, verified account requirement and safe command dry runs.

A **real browser review is still required** at 320, 375, 768, 1024 and 1440 CSS-pixel widths:

- [ ] Tour detail booking CTA and published tour request page, including empty/sold-out/no-departure messages.
- [ ] No horizontal overflow on mobile; long guest email/URL strings wrap safely.
- [ ] Tab and Shift+Tab reach each field and action; visible focus indicator; accessible field labels and error summaries.
- [ ] Keyboard-only quote preview / acceptance / back navigation; form submission loading and duplicate-click handling.
- [ ] Guest verification and manage pages work from real email links and expired-link feedback is understandable.
- [ ] Customer request index and staff review tables work on phones/tablets, with responsive overflow contained inside intended components.
- [ ] Error and success messages are understandable, contrast adequate, controls appropriately labeled, status change announced to screen readers where feasible.
- [ ] No unredacted guest email/phone in public page props, JSON, browser logs, or analytics payloads.

## P3E.6D — Regression, closure and operational handoff

Keep **three independent database roles**:

| Role | Example | Allowed activity |
|---|---|---|
| Development data | `bablys_travel` | Manual development; NEVER run destructive tests against it |
| Normal suite | `bablys_suite_clean_test` | Clean ordinary Laravel test suite, isolated fixtures |
| Real races | `bablys_race_test` | Explicit opt-in multi-process MySQL tests, durable records |

A race run leaves committed fixtures in its DB. **Do not run ordinary tests against the contaminated race database.** Do not use `migrate:fresh`, `db:wipe`, or delete migration history on any non-disposable database.

Required gates:

- [ ] `BookingFinalSecurityRegressionTest` green.
- [ ] `BookingFinalLifecycleIntegrationTest` green.
- [ ] `BookingFinalHttpContractTest` green.
- [ ] Full `php artisan test` green on normal-suite DB (expected skips documented).
- [ ] Opt-in MySQL concurrency suite green on dedicated race DB (skips are not proof).
- [ ] `vendor\bin\phpstan analyse` no errors.
- [ ] `npm.cmd run types:check` and `npm.cmd run build` successful.
- [ ] `php artisan schedule:list` contains request expiry and booking-overdue tasks; a real OS scheduler invokes Laravel's schedule runner.
- [ ] Responsive/a11y/privacy manual checks completed and signed off.
- [ ] No online payment gateway, financial settlement, refund action, public seat hold or staff HTTP conversion endpoint introduced without separately approved scope.

**Closure rule:** P3E.6 is **not completed merely by installing this pack**. Record the final test outputs plus manual checklist, and obtain owner acceptance.
