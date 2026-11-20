# Bablys Tours & Travels — P3F.4A acceptance

## Boundaries

This increment changes **only the page-level frontend layout and the booking-form navigation**. The existing P3E booking request service, mail verification, quote acceptance, idempotency, staff-only booking conversion, payment policy and seat allocation stay unchanged. No new routes or migrations.

All of these routes must have a **Bablys website header and footer** and never show Laravel starter AppSidebar/CMS navigation: `/tours/{slug}/booking-request`, `/booking-requests/submitted`, `/booking-requests/verify`, `/booking-requests/manage`, `/account/booking-requests` (when signed in and verified). Keep `/tours` and `/tours/{slug}` on the same public shell.

## Manual browser acceptance (mandatory)

1. Desktop 1440px, tablet ~768px, mobile 375px: public header, footer, skip link, no sidebar; tour detail → **Enquire Now** → correct tour title on the form. Breadcrumb **Tour details** returns to that exact tour.
2. With test-ready published tour, submit as guest with consent. Form shows non-binding price preview only. HTTP remains POST for mutation; 419 CSRF, throttling, and form errors are handled. No booking/seat/payment before staff conversion.
3. Inspect confirmation email: verification and guest management secrets are only **fragment** components of links, never query parameters. Verify requires explicit POST by user; GET must not verify. Guest status requires correct access token, and withdrawal uses POST. Private fragments are removed from the visible URL immediately on load. Avoid logging, screenshots, copying or analytics on secret links.
4. Guest quote approval is possible only after verification; invalid fingerprint and stale revisions are refused; approval does not reserve seats. Check approved copy remains **not booked**.
5. Signed-in verified customer sees only own requests; another account cannot view/withdraw or approve quote. Test signed-out access to account page and unverified account behavior.
6. Confirm the header does not include an admin/dashboard/sidebar menu in any guest or customer step. Test tab navigation, focus visible, labels, disabled status, and 200% zoom. Confirm no horizontal scrolling or overlapping action controls.
7. Revisit a draft/scheduled/unpublished tour direct booking-request URL: 404. Verify the test-only email delivery driver never exposes real customer tokens on shared machines.
8. Run automated focused tests and full suite against `bablys_suite_clean_test` only; independent race suite remains on `bablys_race_test`. In normal development shell, run PHPStan, `npm.cmd run types:check`, `npm.cmd run build`.

## Scope deferred to P3F.4B

Enhance departure/variant contextual preselection **only after reviewing the booking backend selection and immutable request rules**. Do not use public preview departure IDs as authoritative inventory. No payment collection, guaranteed availability, or auto-confirmation.
