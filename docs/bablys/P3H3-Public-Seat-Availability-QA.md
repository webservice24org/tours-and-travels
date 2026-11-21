# P3H.3 — Public Seat Availability & Booked Counts

## Accepted business rules

- A booking *request* or quote does **not** commit inventory. A verified request still occupies zero seats.
- Authorized staff **conversion** of a traveler-approved request creates a `pending_confirmation` booking and commits its travelers' seats. This reduces available seats **before** confirmation.
- Confirmation changes `pending_confirmation` to `confirmed` and does **not** deduct seats twice. The same committed seats move from the pending counter to the confirmed counter.
- Cancellation of an eligible unpaid booking releases the original allocation **once**; paid-booking cancellation stays blocked pending the approved refund policy.
- Public `available_seats = max(0, capacity_total - seats_committed)` using the departure's committed inventory, not a client count and not merely `confirmed` bookings.
- `confirmed_seats` and `pending_seats` aggregate the `seats` column for active matching `TourBooking` records. `confirmed_bookings`/`pending_bookings` count bookings, not traveler seats.
- `other_committed_seats` covers allocation records not represented by active `TourBooking` rows. This avoids claiming those seats are free. No internal reference/PII leaks.
- The tour detail shows upcoming open future departures even if fully booked, labelled **Currently fully booked** with no direct departure enquiry link. The booking-request page continues to include only *currently requestable* departures.
- All displays are **read-only snapshots**, refreshed by a new page request. No WebSocket/push promise, no countdown or unverified real-time claim. The POST quote, booking request, and admin conversion services still recheck availability.

## PHP/HTTP verification

Use `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_DATABASE=bablys_suite_clean_test`, `DB_USERNAME=root`, `DB_PASSWORD=`, `BABLYS_REAL_MYSQL_RACE=` in a **new CMD session**. Check the effective DB (including phpunit.xml overrides) before running any migration/RefreshDatabase tests:

```bat
php artisan tinker --execute="echo app()->environment().' | '.DB::connection()->getDatabaseName();"
php artisan test --filter=PublicTourSeatAvailabilityTest --stop-on-failure
php artisan test --filter=PublicTourItineraryDepartureTest
php artisan test --filter=PublicTourSeoSecurityClosureTest
php artisan test --filter=PublicTourReleaseSecurityBoundaryTest
php artisan test --filter=AdminBookingActionControlsTest
php artisan test
```

In a separate **normal development CMD session**:

```bat
php -l app\Domain\Travel\Tours\PublicTourSeatAvailability.php
vendor\bin\phpstan analyse
npm.cmd run types:check
npm.cmd run build
```

## Manual browser sign-off

1. Choose a real published tour with an upcoming Open departure and an unexpired booking cutoff. Visit `/tours/{slug}`. Verify **Total / Confirmed / Pending / Available** seats for each date.
2. Open `/tours/{slug}/booking-request`. Verify selected departure and matching counts; changing selected date must update the displayed summary. The request form remains nonbinding.
3. Submit a **guest or customer request**. Verify counts do NOT change when merely requested, verified, quoted or accepted.
4. Traveler approves the current quote; senior authorized staff converts the request at `/admin/booking-requests`. Refresh the public tour page. **Pending seats increase; available decreases.**
5. Staff confirms the booking at `/admin/bookings`. Refresh the public page. **Confirmed seats increase, pending seats decrease, available seats unchanged**.
6. Cancel a separate unpaid booking. Refresh; confirmed/pending seats decrease as appropriate and available seats increase, exactly once.
7. Fill a departure: tour detail shows `Currently fully booked`, without a date-specific enquiry CTA; booking-request dropdown must exclude the filled departure.
8. Check network/Inertia props for **absence** of `internal_notes`, `meeting_point`, `booking_closes_at`, raw `seats_committed`, guest tokens, contact details and booking references. Aggregate public `seat_summary` is intentionally visible.
9. Verify mobile, zoom, keyboard focus, labels and that the **public Bablys header/footer** shows without `AppSidebar`.
10. Refresh page after each admin action; this milestone intentionally does not do background polling or push updates. On production staging, verify reverse-proxy/HTML caching doesn't serve stale public seat counts.

## Next phase (user-requested)

Customer login/account booking history and management, keeping booking requests separate from actual bookings, verified email/owner authorization, and guest token privacy. Then P3H final QA and later CMS global site settings/menus.
