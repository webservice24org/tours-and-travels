# Bablys Tours & Travels — P3H.1 Admin Booking Dashboard & Details

## Scope

- Private GET `/admin/bookings` dashboard, status/payment/search filters, SQL pagination (20/page), booking counts and seat totals.
- Private GET `/admin/bookings/{reference}` details, immutable server-generated booking price snapshot, linked source request contact, current departure inventory.
- `tours.edit` **and** `tours.publish` permissions, active verified CMS staff, no-store and noindex headers. No public route or booking mutation.
- Reuses existing `BablysAdminLayout` and no duplicate sidebar.

## Definitions (do not confuse)

- **Booking records** = rows of `travel_tour_bookings` in `pending_confirmation`, `confirmed`, or `cancelled` status.
- **Confirmed seats** = sum of travelers on confirmed booking records.
- **Pending seats** = sum of travelers on pending booking records. These are **already committed** inventory from prior verified staff conversion.
- **Departure committed seats** = `travel_tour_departures.seats_committed`. May also include allocations outside booking records; it is the inventory source of truth.
- **Remaining seats** = `max(0, capacity_total - seats_committed)`, a changeable staff-only snapshot in P3H.1. Public display will be added separately in P3H.3.
- **Booking request** is nonbinding. A request does not become a booking until the authorized staff conversion workflow executes.
- **Payment state** is distinct from booking confirmation. No payment gateway or payment capture is introduced here.

## Installation QA

1. Back up the Laravel project / save a Git commit. Run PowerShell installer `-WhatIf`, inspect all 6 ADD items and the single guarded route-registry append.
2. Install; inspect the automatically appended `require __DIR__.'/bablys_admin_bookings.php';` at the **end of routes/web.php**. This is the only existing file touched; its old bytes are saved in an installer backup. If your route registry requires different ordering, set `-SkipRouteRegistration` and manually include the new file once in the appropriate route registry.
3. `php artisan route:clear` and `php artisan route:list --path=admin/bookings` — both GET/HEAD and protected by CMS permission middleware.
4. In **CMD**, select `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_DATABASE=bablys_suite_clean_test`, `BABLYS_REAL_MYSQL_RACE` empty; verify with `php artisan tinker --execute="echo app()->environment().' | '.DB::connection()->getDatabaseName();"`.
5. Execute `php artisan test --filter=AdminBookingDashboardReadonlyTest --stop-on-failure`; then prior booking and P3G suites, followed by `php artisan test`.
6. In a **different normal dev terminal**, run `vendor\bin\phpstan analyse`, `npm.cmd run types:check`, `npm.cmd run build`.

## Browser / security acceptance

- Login as administrator, open `/admin/bookings`. See actual bookings created by prior booking conversion or internal staff creation; no placeholder rows or fabricated metrics.
- Filter confirmed/pending/cancelled. Search by actual reference or tour title. Use pagination. Inspect amount/currency from the booking **snapshot**, not the current editable tour price.
- Open detail. `request_contact` appears only if the booking originated from a linked request. Other staff-created bookings have no request contact.
- Confirm inventory numbers are read live from the linked departure; pending and confirmed bookings hold seats, cancellation releases seats. Do not assume booking count equals seats booked.
- Verify ordinary registered customers, unverified/suspended staff and staff without `tours.publish` are forbidden.
- Inspect Inertia props and network payloads: no guest access or verification secret, hashed lookup, encrypted contact column, payment evidence reference hash, private audit data or raw booking model serialization.
- POST `/admin/bookings` and POST to detail must be rejected (405). There are **no** confirm, cancel, reconcile or conversion buttons in this phase.
- Confirm the page uses the existing **admin** layout with exactly one sidebar. Public pages must continue to use their **separate, sidebar-free** Bablys website layout.
- Current `/admin/booking-requests` remains the request review page; `/admin/bookings` is the actual booking management view. The native permission-filtered sidebar link may need an additional small menu integration patch if the local navigation source was customized; do not expose booking links to ordinary `tours.view`-only roles.

## Open work / exit gate

- **P3H.2:** Add authorized staff actions to convert approved requests, confirm bookings and cancel them only through existing domain services, observing quote/reapproval and refund policy.
- **P3H.3:** Provide **public** available seats, booked-seat count and clear snapshot/availability caveats from the authoritative committed ledger, not from guessed booking counts.
- **P3H.4:** Status updates and integrated security/concurrency/regression QA. Only then move to P4A Global Site Settings.
