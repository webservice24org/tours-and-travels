# Bablys Tours & Travels — P3H.5 Final Booking Release Acceptance

**Scope:** P3E booking foundation, P3F public tour enquiry, P3G public-tour QA, P3H.1 admin dashboard, P3H.2 booking actions, P3H.3 public availability and P3H.4 verified customer account.

## Manual journey A — customer booking

1. Create/register a customer account through the existing Laravel/Fortify login and verify the email. Unverified customers must not see private booking pages.
2. Visit a **published**, upcoming, open departure with a future request cutoff, available seats and an active price variant. Confirm the public detail and booking-request form show the same seat snapshot and clearly state that an enquiry does not reserve seats.
3. Submit the request. On `/account/booking-requests`, verify the reference and **Review current quote**. At this point: no booking, no committed seat, no payment.
4. Without approval, an authorized staff member tries **Convert** on `/admin/booking-requests`. The server must refuse conversion and preserve capacity.
5. Customer explicitly approves the current quote. Staff converts once. Verify **one** booking and seat commitment; admin sees `pending_confirmation`; `/account/bookings` shows the same booking; public `pending_seats` rises and `available_seats` falls.
6. Admin confirms the booking in `/admin/bookings/{reference}`. Verify customer shows `confirmed`; public confirmed seats increase while pending seats decrease; **available seats do not fall a second time**. Repeat confirmation: no duplicate allocation or audit event.
7. For a separate unpaid booking, cancel with explicit confirmation. Verify `cancelled` in the account, one seat release, public counts recalculated. Repeat cancellation: no second release. Confirm paid bookings cannot be cancelled through this flow without an approved refund policy.
8. After a price/variant change, previously accepted quote must no longer authorize conversion until the owner approves the latest quote. Customer must see original pricing snapshot for already-created bookings.

## Manual journey B — ownership and privacy

- Logged-out user: `/account/travel`, `/account/bookings`, `/account/bookings/{reference}` redirect to login.
- Unverified logged-in user cannot access the customer travel area.
- Customer B cannot read customer A's booking by guessing its reference; response is 404. A guest booking is not auto-owned just because the guest submitted the same email as a customer account.
- Only active and verified staff with both `tours.edit` and `tours.publish` can convert, confirm, cancel and read staff-only booking detail/contact information.
- Customer and staff booking pages have `X-Robots-Tag: noindex, nofollow`, a private/no-store cache policy, and no hidden guest secret, email ciphertext, token hash, allocation ID, payment evidence or audit internals in Inertia props.
- Header menu and account history use the standalone public website shell; no AppSidebar or duplicate admin layout on public/customer pages.

## Manual journey C — inventory and time

- Compare public total capacity, confirmed booked seats, pending seats, *other committed* seats and available seats against a carefully chosen test departure. Booking count means the **number of booking records**, not traveler-seat count.
- A request-only record must not affect availability. Other committed allocations must still reduce available seats even if not represented in confirmed booking records.
- Fully booked, closed, expired or unpublished departures must not offer an actionable date in the booking-request form.
- Refresh public and customer pages after admin changes: these are **server snapshots**, not live sockets. Final allocation always rechecks availability under server-side locks.

## Operations & release boundaries

- Test only on `bablys_suite_clean_test` in `APP_ENV=testing`. Backup the dev DB if any previous tests were accidentally run in development CMD. **Never** run migrations/testing against real customer data.
- PHPStan, TS typecheck, Vite build and the full Laravel suite must be green.
- The distinct P3E.5B.3D real-MySQL race suite is an independent, opt-in test with its own disposable database; retain its previously passed lock/idempotency fixes.
- Verify real email delivery, guest request verification, expiry worker/scheduler, payment-status policy, HTTPS, redirects and recovery in staging before production sign-off.
- Do not treat a successful booking conversion as payment collection, and do not expose unverified bookings as confirmed.

## Approval record

| Item | Accepted? | Evidence / date / reviewer |
|---|---|---|
| Customer login + quote approval | ☐ | |
| Staff conversion and booking confirmation | ☐ | |
| Cancel/payment safeguard | ☐ | |
| Customer history + owner isolation | ☐ | |
| Public seats and cross-tour isolation | ☐ | |
| Privacy, caching and SEO exclusion | ☐ | |
| Automated suite + static gates | ☐ | |
| Staging email, scheduler, backups and rollback | ☐ | |

When all acceptance gates pass, close P3H.5 and proceed to **P4A — Global CMS Settings Foundation**.
