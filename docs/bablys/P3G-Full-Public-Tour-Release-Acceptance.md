# Bablys Tours & Travels — P3G Final Public Tour Acceptance

## Release gate ownership

**Automated tests** are necessary, not sufficient for public launch. Fill in the evidence and owner/date for every gate. P3G is not accepted until the application tests, static checks, real MySQL contention baseline, deployment/browser privacy and accessibility checks are signed off. This document is a check record, not a statement that your app has passed.

| Gate | Evidence / result | Approved by / date |
|---|---|---|
| P3G focused integration and security suites | | |
| Full Laravel test suite; expected skips explained | | |
| PHPStan (zero errors) | | |
| TypeScript `types:check` and Vite production build | | |
| P3E.5B.3D real MySQL race suite on isolated `bablys_race_test` | | |
| Desktop and mobile manual browser flows | | |
| Keyboard + screen reader + reduced motion + 200% zoom | | |
| Crawler/canonical/SSR response checked on deployed HTTPS | | |
| Private data/redaction reviewed in network payloads | | |
| Production backups, HTTPS, APP_URL, mail transport and rollback plan | | |

## End-to-end acceptance (public site has NO dashboard sidebar)

- [ ] Tour search, category/destination filters, stable sorting and >50 published-tours pagination work. Inspect 320 / 375 / 390 / 768 / 1024 / 1440px; no unintended horizontal scrolling.
- [ ] Card cover images and detail galleries render from actual PUBLIC media only; empty/missing image fallback and thumbnail keyboard support work; no private disk paths or uploader IDs appear in Inertia payloads.
- [ ] Unpublished or future-scheduled tours remain 404 on detail and enquiry routes. Revoking publication removes access immediately.
- [ ] Itinerary displays day order, title, text, location, meals, highlights and overnight stops; encoded/HTML input is displayed as safe text.
- [ ] Open upcoming dates display as UTC-based dates with source IANA time zones; staff notes, capacity, committed seats, booking deadlines, internal row IDs and price ledgers are absent from public tour detail payloads.
- [ ] Choosing a departure opens the enquiry form with the correct date selected; stale, closed, sold-out, cross-tour and malformed hints require a fresh choice and never reserve inventory.
- [ ] Guest enquiry with consent yields email containing fragment-only proof/access secrets; verify flow is deliberate (GET alone never verifies). Verify links are not logged by production mail drivers. Wrong-token guest status returns 404.
- [ ] Customer verified-account request, owner-scoped retry idempotency, quote review, quote acceptance, staff conversion, confirmation and cancellation obey the approved offline-payment and refund policy. No public seat booking or card payment is implied.
- [ ] Keyboard: skip link, mobile menu toggle/Escape/focus return, filter labels, pagination announcement, gallery, enquiry validation focus, private status updates. Test with NVDA, at 200% zoom and reduced motion; manual result recorded.
- [ ] Public `/tours` indexable with correct self-canonical; `/tours?page=2` own canonical; filtered/sorted/search result returns `X-Robots-Tag: noindex, follow` and main listing canonical. In production HTTP/SSR, independently inspect the REAL rendered `<head>` and response headers.
- [ ] Existing static https://bablys.club/ URL inventory matched to new Laravel routes. Approved permanent redirects, consistent canonical HTTPS hostname, no duplicate content; migrate only after approval, backups and rollback rehearsal.

## Security & operations

- [ ] Dedicated ordinary suite database `bablys_suite_clean_test` confirmed; no test uses `bablys_travel`. Dedicated destructive race database `bablys_race_test` remains separately controlled. Race fixtures MUST NOT pollute ordinary test suite.
- [ ] Authentication, administrator/staff permissions, CSRF (real browser), request rate limiting (real HTTP), safe logs, cache-control private/no-store, mail credentials and audit trail checked on target deployment configuration.
- [ ] Check final deployment configuration (`APP_ENV=production`, `APP_DEBUG=false`, HTTPS, cookies, queues, cron/scheduler, filesystem visibility, mail provider); never paste secrets into QA report.
- [ ] Review sensitive Inertia JSON using DevTools: no staff inventory/notes, booking references, raw verification/access tokens, customer email encrypted columns, money ledger or payment state in *public* pages.
- [ ] Approve exceptions/known risks explicitly; do not claim WCAG compliance, SSR readiness or launch clearance from PHPUnit/source-contract tests alone.

## Signoff

- **Release candidate version / commit:**
- **Target host / URL:**
- **Test database(s) used:**
- **Automated QA summary:**
- **Manual UX / accessibility findings:**
- **SEO / crawler findings:**
- **Privacy / security findings:**
- **Unresolved issues / decisions:**
- **Reviewer, approval date, go/no-go:**
