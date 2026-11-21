# P3H.4 — Customer Account, Booking History & Verification QA

This phase **reuses the existing Laravel/Fortify login, email verification, and guest booking request security**. It does not create a second user database or authentication service.

## Routes
- `GET /account/travel` — logged-in, email-verified customer overview.
- `GET /account/bookings` — own converted bookings, 15 per page.
- `GET /account/bookings/{reference}` — detail of one own linked booking, 404 for another customer's or unlinked booking.
- Existing `GET /account/booking-requests` remains quote approval/withdrawal management, now linked from the customer shell.
- Existing `/booking-requests/manage` guest email-token flow remains unchanged.

## Ownership/privacy invariants
1. Never infer booking ownership from email or name. A booking is displayed only if `travel_tour_booking_requests.customer_id = auth()->id()` and the owned request's `booking_id` matches the booking. Direct staff bookings and guest-only bookings do **not** appear in this panel.
2. Auth + verified email is required for all newly added GET pages. Unverified or anonymous users must not see private data.
3. The server emits an explicit safe DTO, not Eloquent model serialization: no secret tokens, encrypted contacts, guest access secrets, allocation IDs, audit/PII or payment evidence.
4. Customer pages send `Cache-Control: private, no-store, max-age=0` and `X-Robots-Tag: noindex, nofollow`.
5. Historical booked price and payment_state are informative only; the panel **cannot** collect payments, approve quotes on behalf of others, cancel bookings, or allocate seats.
6. Existing quote approval panel remains on `/account/booking-requests`, with ownership enforced by its existing manager.

## Manual browser sign-off
- [ ] Logout. Visit `/account/travel` and verify redirect to the **existing** `/login`, not a new bespoke login implementation.
- [ ] Sign in with a verified customer; use the new public header 'My Account' link. Dashboard is sidebar-free, responsive and keyboard-usable.
- [ ] Log in with a different customer and attempt to open a guessed reference from the first customer's booking. Should be **404**.
- [ ] Verify `/account/booking-requests` still displays quote-review actions and withdrawal controls. Old guest private links must still work.
- [ ] Submit/approve a verified customer request, convert via senior-staff `/admin/booking-requests`, then check customer dashboard: pending booking appears, no additional seat allocation.
- [ ] Confirm via `/admin/bookings`, refresh customer panel: status changes to confirmed and pending goes down, committed seat count is unchanged.
- [ ] Cancel an **unpaid** booking via staff action: customer status changes to cancelled; inventory releases once.
- [ ] Check browser Network Inertia payloads for no guest tokens, encrypted contacts, internal notes, staff-only IDs or payment evidence.
- [ ] Verify cache/noindex headers on normal and Inertia navigation, and ensure browser back after logout does not retrieve private data from cache.
- [ ] Check narrow mobile widths, 200% zoom, focus navigation, screen reader headings, and keyboard sign-out.

## Notes
Guest requests cannot be automatically claimed by a newly registered customer, even if the email matches. An explicit token-verified transfer workflow could be considered in a separate approved phase. Tests do **not** operate on ordinary dev DB or dedicated race DB.
