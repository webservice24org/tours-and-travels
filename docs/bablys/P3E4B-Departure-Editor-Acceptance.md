# Bablys P3E.4B — Visual Departure Editor Acceptance

Scope: internal departure and seat-capacity management only. P3E.5 must implement booking readiness, real allocation/release and transactional reservation flows. **Open does not mean publicly bookable.**

## Preconditions

- P3E.4A migration and 39 domain tests completed; P3E.3B full-page Tour Editor installed and green.
- Use a **disposable** testing database for automated tests (Laravel RefreshDatabase).
- Keep the single existing Laravel `AppSidebar`, shared `confirmAction` + toasts, MicroWeb Tiptap, pricing, and itinerary.
- Ensure `routes/bablys_admin.php` is loaded and `php artisan route:clear` has run.

## Browser checks

1. Sign in as administrator; edit a tour at `/admin/tours/{id}/edit`. Confirm only one sidebar and the new **Departure Dates & Capacity** section in the main 9-column area, alongside the Pricing and Daily Itinerary sections.
2. For a new tour, verify the prompt to **save the tour first**; no departures can be entered without a saved Tour ID.
3. Create a departure named **Summer 2030** with stable code `summer-2030`, timezone `Asia/Dhaka`, start `2030-06-10 08:00`, end `2030-06-12 08:00`, cutoff `2030-06-09 18:00`, capacity 25, status **Open**. Save; reload; expect all wall times preserved and correct UTC data (`2030-06-10T02:00:00Z` start).
4. Add another departure in a different timezone (for example `Europe/London` with valid summer offset). Verify the local wall time survives save/reload; no accidental browser-timezone conversion. Do **not** use incorrect UTC offsets. In DST gaps/folds, choose a clear unambiguous local time; the UI rejects nonexistent or ambiguous wall times instead of guessing.
5. Change meeting point, staff notes, and capacity; Save Departures without submitting Tour Details, Tiptap, Pricing or Itinerary. Verify toast and refreshed inventory counters.
6. Duplicate a departure and verify generated unique code; save and confirm chronological order. Remove an uncommitted row and confirm the **shared Confirm Dialog** opens; cancellation must leave the row. Removal commits only on Save Departures.
7. Test Discard Changes; confirm a modal appears if there are unsaved edits, and the server's values are reloaded.
8. Simulate committed inventory in the testing environment (do not directly edit production): verify code, timezone, date fields and removal are locked; ensure capacity cannot shrink below the committed count. Server rejects malicious PUT requests, too.
9. Verify `public_bookable` always false, even when an internal departure is **Open** and remaining capacity is positive. No checkout, payment or booking is created.
10. Test mobile width: editor stacks, action buttons are visible, no extra sidebar or horizontal overflow.
11. Validate a content editor can manage draft tours, but cannot mutate departures on published/scheduled tours; administrator can. A designer or customer cannot mutate departure inventory; revocation takes effect on the next request.
12. Check audit event `tour.departures.changed`: only counts and changed codes, not meeting points, notes, timestamps, seat counts or raw payload.

## Exact API contracts

- `GET /admin/internal/tours/{tour}/departures` returns `{data: Departure[], meta: {status, inventory_only:true, public_bookable:false, as_of}}`.
- `PUT /admin/internal/tours/{tour}/departures` accepts `{departures:[...]}`; an explicit `[]` clears **uncommitted** records only. Missing list is 422.
- Accepted departure keys: `code`, `name`, `timezone_name`, `starts_at`, `ends_at`, `booking_closes_at`, `capacity_total`, `status`, `meeting_point`, `internal_notes`.
- Date fields require ISO 8601 **seconds and an explicit UTC offset** matching the named IANA timezone for that instant. Server normalizes to UTC.
- Never submit `seats_committed`, `remaining_capacity`, `capacity_state`, `public_bookable`, database IDs, pricing totals, or payment fields.
- Same `code` retains ID and future bookings' seat commitments. Removing/replacing codes with committed seats is blocked.

## Commands

```powershell
cd C:\xampp\htdocs\bablys-travel
php artisan route:clear
php artisan route:list --path=admin -v
php artisan test --filter=AdminTourDeparturesUiTest
php artisan test --filter=TourDepartureManagerTest
php artisan test --filter=TourDepartureRulesTest
php artisan test
vendor\bin\phpstan analyse
npm.cmd run types:check
npm.cmd run build
php artisan bablys:audit:readiness
```

## Limitations / handoff to P3E.5

This is *not* a booking system. The panel displays stored capacity snapshots but is not a live seat hold/reservation authority. No public booking guarantees, allocations, order payments, or automatic seat changes are introduced. Later booking workflows must lock the **parent tour first**, then the departure row, in the same order as the P3E.4A manager.
