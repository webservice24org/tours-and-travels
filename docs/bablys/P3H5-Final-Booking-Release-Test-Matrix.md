# P3H.5 — Final Customer/Admin Booking Integration Test Matrix

**Bablys Tours & Travels ONLY** · Laravel 13 · Inertia React TSX

## P3H.5 focused automated suites (23 new tests)

| Suite | Tests | Purpose |
|---|---:|---|
| `BookingCustomerAdminReleaseJourneyTest` | 8 | Non-binding quote → accepted quote → authorized conversion → pending booking → confirmation/cancellation → customer history and public seat counts; original prices, idempotency, tamper resistance, repeated read-only views |
| `BookingReleaseSecurityPrivacyTest` | 8 | Guest/unverified access, senior staff permissions, cross-account isolation, same-email guest isolation, stale/unapproved quote rejection, paid cancellation refusal, noindex/no-store/secret redaction, sold-out availability |
| `BookingReleaseSourceContractTest` | 7 | GET-only verified customer routes, POST-only privileged staff actions, owner-only booking lookup, private headers, authoritative seat totals, sidebar-free public/customer layouts and quote/domain service boundaries |

### Existing regressions that must continue to pass

- `CustomerTravelAccountTest` — P3H.4 plus the **P3H.4 Test Compatibility Fix** (order-insensitive private cache header, customer layout nesting).
- `AdminBookingActionControlsTest`, `AdminBookingDashboardReadonlyTest` — P3H.2/P3H.1.
- `PublicTourSeatAvailabilityTest` — P3H.3 inventory.
- `PublicBookingEnquiryJourneyTest`, `PublicTourReleaseJourneyTest`, `PublicTourReleaseSecurityBoundaryTest` — P3F/P3G.
- `TourBookingQuoteAcceptanceTest`, `BookingFinalLifecycleIntegrationTest`, `TourBookingLifecycleTest` where present.
- Full `php artisan test` in the isolated test database.

### Database boundaries

New behavioral suites refuse to start before `RefreshDatabase` unless `APP_ENV=testing`, `DB_CONNECTION=mysql`, and a dedicated non-race database whose name ends in `_test` are present. The separate real-MySQL race suite (`P3E.5B.3D`) is **not** part of P3H.5's normal full-suite invocation; run it only under its separate documented, disposable race test DB and opt-in protocol.

A prior development-CMD run generated 68 misleading failures because it did not have the test environment configured. Do not weaken/remove this safety check. New tests avoid order-sensitive `Cache-Control` comparisons.

### Outcome recording

| Gate | Required evidence | Result |
|---|---|---|
| Focused 23 tests | Passed/failed and assertion count | Pending local QA |
| Previous P3H/P3G regressions | Passed/failed and assertion count | Pending local QA |
| Full Laravel suite | Passed/skipped/failed totals | Pending local QA |
| PHPStan | No errors | Pending local QA |
| TS typecheck + build | Both successful | Pending local QA |
| Browser end-to-end | Customer/staff/public snapshots | Pending manual QA |
| Database/real race regression | Separate explicit verification when needed | Independent gate |

**QA-only:** This package creates no application routes, controllers, migrations, TSX or booking services. A failing test is a blocker to investigate, not a reason to bypass an authorization or allocation rule.
