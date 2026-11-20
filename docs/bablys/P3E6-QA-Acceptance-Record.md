# P3E.6 — Booking QA Acceptance Record (fill after local runs)

Project: Bablys Tours & Travels  |  Date: __________  |  Reviewer: __________

## Automated evidence

| Gate | Result | Test count / assertions | Notes |
|---|---|---|---|
| P3E.6A security regression | Pending | | |
| P3E.6B booking lifecycle | Pending | | |
| P3E.6C HTTP contract | Pending | | |
| Full Laravel suite (normal test DB) | Pending | | |
| Real MySQL races (race DB) | Previous green; recheck after changes | Earlier: 1 test, 72 assertions | |
| PHPStan | Pending | | |
| TypeScript typecheck | Pending | | |
| Production build | Pending | | |

Testing database names used (never passwords):

- Normal suite: ___________________
- Race database: ___________________

## Manual verification

- Browser widths / horizontal overflow: ________
- Keyboard + labels + focus + contrast: ________
- Real email-link privacy / expires / no PII leak: ________
- CSRF, throttling, unauthorized roles: ________
- Scheduler and mail delivery: ________
- Business policy approval (timeouts, offline reconciliation): ________
- Known issues / approved follow-ups: ________

## Exit decision

- [ ] Passed for current **nonbinding** booking-request release (no public checkout/payment gateway).
- [ ] Not passed; remediation required.

Owner sign-off: ___________________  Date: __________
