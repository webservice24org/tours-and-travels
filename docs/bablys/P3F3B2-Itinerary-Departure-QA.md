# Bablys Tours & Travels — P3F.3B.2 QA & Privacy Contract

## Verified schema and presentation

- `travel_tour_itinerary_days`: `tour_id`, `day_number`, `title`, `location_label`, `description`, `overnight_location`, JSON `meals`/`highlights`. Days are immutable read-only for guests, ordered by `day_number`, limited to 60.
- `travel_tour_departures`: `tour_id`, `name`, `starts_at`, `ends_at`, `timezone_name`, `booking_closes_at`, `status`, private `code`, `capacity_total`, `seats_committed`, `meeting_point`, `internal_notes`.
- Public departure previews require `status=open`, future UTC start and a nonexpired (or absent) booking close timestamp. A maximum of eight ordered entries is allowed; each includes only `name`, UTC ISO start/end, and IANA timezone. NO IDs, capacity, internal notes, booking deadlines or prices.
- `Tour::publiclyAvailable()` is enforced by `PublishedTourDetail` BEFORE calling the public read model. The public show page remains under the standalone `PublicSiteLayout`, not the Laravel dashboard sidebar.
- Every departure date is **indicative**. No seat is reserved until an authorized staff workflow confirms it. Enquire Now is the existing nonbinding booking request flow.

## Test checklist

1. Use dedicated disposable MySQL `bablys_suite_clean_test` (not `bablys_travel` and not `bablys_race_test`). Verify `APP_ENV=testing` and database in `php artisan tinker`. Leave `BABLYS_REAL_MYSQL_RACE` unset.
2. `php artisan test --filter=PublicTourItineraryDepartureTest --stop-on-failure`
3. `php artisan test --filter=PublicTourGalleryTest` and `php artisan test --filter=PublicTourDetailFoundationTest`.
4. `php artisan test` in clean test DB; 3 expected skips (race opt-in; two Fortify 2FA settings) unless features/config changed.
5. In a new development terminal: `vendor\\bin\\phpstan analyse`, `npm.cmd run types:check`, `npm.cmd run build`.
6. Browser: `/tours/{published-slug}` has NO dashboard sidebar. Check day list, location, meals, highlights, multiple departures with correct local timezone; responsive, keyboard focus, horizontal scrolling, no HTML execution. Check missing itinerary/departures empty states.
7. Browser: verify draft/scheduled tour 404; future open dates shown, past/closed cutoff dates hidden. Check private fields not present in Inertia JSON responses. Verify enquiry CTA does not place a reservation on detail-page view.

## Rollback

Installer saves backups with `.bablys-p3f3b2-<UTC>.bak` on both replacements. Restore the preceding version of `PublishedTourDetail.php` and `show.tsx`, remove the new schedule service and test/docs if rollback is required. Do not touch migrations or booking tables. Installer performs fail-closed source hash checks and preflight validation before writing.
