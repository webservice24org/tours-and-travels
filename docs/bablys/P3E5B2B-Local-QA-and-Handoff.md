# P3E.5B.2B — Local browser / acceptance checklist

## Routes (added without mutating older booking services)

| Method | URL | Access | Purpose |
|---|---|---|---|
| GET | `/tours/{slug}/booking-request` | Published tour | Public nonbinding request form |
| POST | `/booking-requests/quote` | Public, throttled, CSRF | Current authoritative quote, no seat hold |
| POST | `/booking-requests` | Public or authenticated, throttled, CSRF | Create request, capture consent, deliver guest email |
| GET | `/booking-requests/submitted` | Public | Generic acknowledgement (reference from session) |
| GET/POST | `/booking-requests/verify` | Public | GET only presents verification button; POST verifies |
| GET | `/booking-requests/manage` | Public | Guest private-link UI (tokens in URL fragment only) |
| POST | `/booking-requests/guest/status` | Verified guest + access token | Limited, no-PII status payload |
| POST | `/booking-requests/guest/withdraw` | Verified guest + access token | Withdraw pending request |
| GET | `/account/booking-requests` | Authenticated verified customer | Own status/history, max 50 |
| POST | `/account/booking-requests/{reference}/withdraw` | Authenticated verified owner | Withdraw own open request |
| GET | `/admin/booking-requests` | CMS staff with `tours.edit` AND `tours.publish` | Read-only private review, max 100 |

There is intentionally **no POST /admin/booking-requests conversion endpoint**, online checkout, paid-status API, public seat reservation or `public_bookable` flip.

## Browser acceptance

1. Publish a tour with a published category and destination, an open future departure with spare capacity, and an active valid price variant. Load `/tours/<slug>/booking-request` on a desktop browser and narrow phone. Confirm no horizontal document scroll.
2. Preview a price for 2 adults + 1 child. Compare integer-minor-unit total with P3E.3 server pricing. The message must clearly call it **nonbinding**, and departure `seats_committed` must not change.
3. Submit the guest form without consent and with bad inputs; observe field validation and **no saved request**. Then submit a valid request; note the new `BRQ-...` reference. Do not show token strings in page source, Inertia props, errors or browser response JSON.
4. In the configured test email inbox, confirm one verification link and one separate private management link. Check both contain credentials in `#` fragments; no `?token=...` query. Visiting verification GET by itself **must not verify**. Explicitly click **Verify email** to change `awaiting_verification` → `verified`.
5. Open private management link and choose **View my request status**; verify no phone/email/name, token/hash or internal price lines are returned. A fabricated/wrong access token must fail. Withdraw and confirm the private link cannot be used again.
6. Sign in as a **verified** customer and request another tour. Check `/account/booking-requests` shows only that account's requests. Another verified account cannot view/withdraw them. An unverified account cannot bypass email ownership.
7. Sign in as a CMS administrator with both `tours.edit` and `tours.publish`. Visit `/admin/booking-requests` and confirm restricted contact details; view-only. An unauthenticated user and a staff role without both permissions must not access that view.
8. Double-click submit/retry with the **same** `submission_id` in the same session; verify one DB row and only one email. Submit again with a fresh UUID and expect a distinct request until throttled.
9. Create a verified request, let staff convert it **only through the existing trusted domain service** in tests; recheck actual seat count changes only during conversion. Do not add public conversion UI until price consent & concurrency safeguards pass.
10. Configure an invalid mail transport in a safe test environment. Submission must fail with a generic 503; the inaccessible request should be withdrawn and its idempotency key cleared. Do not display raw secrets in error logs. Do not exercise using real recipients or a live mailbox.
11. Confirm privacy: no request details available via public GET, no unverified guest status read, staff/account responses include `Cache-Control: private, no-store`, and no direct unauthenticated POST bypass for web CSRF in real browser.
12. Run `php artisan test`, `vendor\bin\phpstan analyse`, `npm.cmd run types:check`, `npm.cmd run build`, and `php artisan bablys:audit:readiness` locally. Retest any changes to existing user-auth, routes, CSP, and Inertia root layout.

## Explicit boundaries and next milestones

- **P3E.5B.2B** covers intake/forms, email verification, ownership-scoped status and withdrawal, restricted review, and focused regression. Customer/guest UI is in standalone builder-ready pages; the existing tour details visual builder/sidebar is unchanged. A reusable `resources/js/components/public/tours/BookingRequestCta.tsx` is included. Link the tour-detail CTA using the approved tour data source when integrating dynamic public templates; do not hardcode an unrelated tour slug.
- **P3E.5B.3** should add expiration sweeper & notification/retry policy, customer re-approval of any changed price before actual conversion, cancellation rules, payment capture only if explicitly approved, production-ready operational reconciliation, and two-connection MySQL oversell/race tests. Also review whether a converted guest needs a separate durable non-secret status channel (the 2A access token is deliberately revoked on conversion).
- **P3E.6** runs full end-to-end HTTP/authorization/privacy/concurrency/security/queue/mail/mobile tests and owner acceptance before anything is called production-ready.
- Dedicated `bookings.view`, `bookings.manage`/Booking Agent permissions, pagination/search, assignments/notes, retention/deletion scheduling, and admin navigation should be added in an authorized follow-on CRM step rather than exposing traveler contacts to all `tours.view` users.

**No direct reservations or guaranteed availability yet**; leave `TourBookingReadiness` unchanged.
