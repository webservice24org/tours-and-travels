# P3F.4B — Departure Selection & Enquiry Context: QA / Security Review

Scope: **Bablys Tours & Travels** Laravel 13 + Inertia React TSX. Migrating https://bablys.club/. This is a nonbinding enquiry preference, never a booking or inventory reservation.

## Public journey

1. Browse a published `/tours/{slug}` page. The upcoming departure preview shows date/timezone, without row IDs, capacity, internal notes, booking deadlines or pricing.
2. Click **Enquire about this date**. Browser opens `/tours/{slug}/booking-request?departure_at=<encoded UTC ISO timestamp>`. No secret, payment, token, capacity, or database row ID is placed in this URL.
3. When the departure is still requestable, the form selects it by server-validated `selected_id` from its normal allowlisted `departures` list. A plain enquiry without a hint still defaults to the first eligible departure.
4. When it is sold out, closed, expired or no longer requestable, the form shows a generic warning and **requires the visitor to explicitly select another departure**. It never substitutes another date silently. No raw query value is echoed.
5. The quote POST and request POST continue to recheck current readiness, price, consent, verified customer/guest email flow and CSRF. Simply opening these pages does not create a request, booking, payment, seat allocation or seat commitment.

## Privacy and consistency

- Public previews use existing `PublishedTourSchedule` (open/future/booking-close-time) and do not gain private IDs or seat counts.
- Public form lists only dates belonging to the published tour that are open, future, booking deadline not passed, and have remaining capacity at GET time. This GET filtering is **not** a commitment of seats; authoritative POST still checks under existing server rules.
- `departure_at` must be exact `YYYY-MM-DDTHH:MM:SSZ` and is matched as an exact UTC SQL datetime against the selected tour's current eligible collection. Other query values are not serialized to page props.
- Time is stored as naive UTC SQL DATETIME. The URL hint uses Z, but matching does **not** apply a timezone conversion; display names retain `timezone_name`.
- If multiple eligible rows have the same UTC start within a tour, the server deterministically chooses earliest ordered row; this date preference is not intended to uniquely identify private rows.
- The existing `PublicSiteLayout` is retained for tour details and all guest/customer enquiry pages: **no Laravel dashboard sidebar**.

## Local QA

Use **dedicated** MySQL `bablys_suite_clean_test`, `APP_ENV=testing`, `BABLYS_REAL_MYSQL_RACE` unset. Always verify actual database first; never run destructive test operations against `bablys_travel`.

```bat
php artisan tinker --execute="echo app()->environment().' | '.DB::connection()->getDatabaseName();"
php artisan test --filter=PublicDepartureEnquiryContextTest --stop-on-failure
php artisan test --filter=PublicBookingEnquiryJourneyTest
php artisan test --filter=PublicTourDetailExperienceTest
php artisan test --filter=BookingFinalSecurityRegressionTest
php artisan test
```

Normal development CMD:

```bat
vendor\bin\phpstan analyse
npm.cmd run types:check
npm.cmd run build
```

Browser: inspect desktop/mobile, keyboard controls, valid date-prefill, stale/filled date and explicit correction, quote retry, signed-in and guest journeys, header/footer, no sidebar; check network/page source for no secret/seat/capacity/price claim.
