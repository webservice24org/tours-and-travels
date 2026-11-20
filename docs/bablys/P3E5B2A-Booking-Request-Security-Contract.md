# P3E.5B.2A — Booking request security contract

## What exists

A **booking request** is not a booking and does not hold seats. The new `travel_tour_booking_requests` table stores a candidate tour/departure/pricing option and contact details **encrypted at rest**. A separate P3E.5B.1 booking is created **only after trusted staff conversion**, which invokes the existing transaction-bound seat-allocation manager and rechecks availability and the current server-calculated price.

- Guests provide a name, email, optional phone, and traveler counts to the **domain** method `createGuest`. The result contains one-time raw tokens for the **future out-of-band email integration**. Only SHA-256 digests are persisted. `verifyGuest` checks proof of possession and clears the verification digest. Guest withdrawal requires the **separate** access token.
- Signed-in customer requests use `createCustomer`, which requires an email-verified User. Contact name/email come from that authenticated User, not arbitrary form fields. `withdrawCustomer` enforces matching User ID.
- `convertVerified` is **staff only** (`tours.edit` AND `tours.publish`). It uses the immutable server-derived `BKG-<request-reference>` and existing P3E.5B.1 `createInternal`, which runs the authoritative quote and P3E.5A atomic ledger under the same DB transaction and locks.
- Converted requests can be retried by authorized staff without creating a second booking. Expired or withdrawn requests cannot convert. A true cancellation must happen on the linked booking lifecycle (to release seats), not by withdrawing a converted request.
- Contact identity fields are encrypted with the current Laravel `APP_KEY`. The email lookup index is a **keyed HMAC**, not a raw email or plain SHA of an email. Plan APP_KEY rotation and PII retention before deployment.

## Important NOT included

**No public HTTP routes**, no emails, no client UI, no public booking activation, no rate limiting, no captcha/honeypot, no consent collection, no scheduled expiry/reconciliation worker, no customer-visible booking history, no payment integration, no money capture/refunds, and no production MySQL concurrent-request evidence.

The service is **not** safe to bind directly to an unrestricted public controller. In the next stage, the controller must:

1. Apply web/session CSRF where appropriate, strict input validation, IP + account/email-scoped throttles, and a non-oracular response for guest identities.
2. Deliver secrets through a configured email provider; never return verification/access tokens in public JSON, HTML, logs or toasts. Do not send booking reference/PII to analytics.
3. Verify email ownership before any customer/guest request details are disclosed. Authenticate customers via session middleware, require verified email, and scope all reads/mutations to the owner.
4. Include privacy notice, retention windows, purpose/consent evidence and protected admin view. Redact access logs and analytics.
5. Keep `public_bookable=false` until the separate checkout/payment/cancellation policy and MySQL two-connection oversell tests are green.
6. Provide a quote preview labeled **nonbinding**, with no availability guarantee. At conversion the staff service reprices, and a customer-consent/reconfirmation step is needed if price changed.
7. Reconcile request expiry with departure cancellation and real booking lifecycle, and implement notice/operational reporting.

## Lock ordering

- Conversion locks the **parent tour**, then the booking request, then delegates into the existing booking manager (which locks the tour, booking reference, allocation and departure in P3E.5A's documented order).
- Email verification and request withdrawal lock **only** the request row; they do not subsequently acquire the parent tour lock.
- Booking price/capacity is **not reserved** until the verified request is converted, at which point all earlier eligibility conditions are checked again.

## Public readiness remains disabled

**Do not** change `TourBookingReadiness`'s `public_bookable=false` invariant in this phase. These booking requests are private domain foundation—not public reservations.
