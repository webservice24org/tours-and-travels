# P3G — Test matrix and safety constraints

| Boundary | Regression evidence |
|---|---|
| Listing → detail → departure hint → enquiry → quote | `PublicTourReleaseJourneyTest` |
| Filter/search/sort → canonical and X-Robots-Tag | `PublicTourReleaseJourneyTest`, `PublicTourReleaseSecurityBoundaryTest` |
| Publication revocation / draft + scheduled + archived | Both behavioral suites |
| Public card/gallery media and staff-only data | `PublicTourReleaseSecurityBoundaryTest` |
| Itinerary plain-text and departure operational redaction | `PublicTourReleaseSecurityBoundaryTest` |
| Guest email proof / hidden private access token | Both behavioral suites |
| Customer owner-scoped idempotency and quote conversion | Both behavioral suites |
| Sidebars, keyboard/ARIA, canonical and headers source contracts | `PublicTourReleaseSourceContractTest` |
| Load/response timing, HTML head, actual SSR, DevTools privacy, WCAG/manual | `P3G-Full-Public-Tour-Release-Acceptance.md` (manual) |
| Real MySQL booking worker races and locks | Prior `TourBookingRealMySqlConcurrencyTest` in **separate** `bablys_race_test` only |

This pack is **add-only**. No migrations, production controllers, source TSX, billing or booking/seat state-machine changes. Use the clean ordinary suite DB. Avoid running `migrate:fresh` against any database except explicitly approved disposable databases. The tests use `TourBookingQaTestCase`/`RefreshDatabase`; a contaminated or mistaken database invalidates results and can be dangerous. The P3G runner sets temporary environment variables for its own process and checks the effective Laravel connection, but verify phpunit.xml/.env.testing overrides before running PHPUnit. It does not run the separate MySQL race test; run that suite intentionally against the race database.
