# Bablys Tours & Travels — P3F.5C SEO & Frontend Security Closure

## Scope and constraints

This step hardens **filtered public tour listing HTTP responses** with `X-Robots-Tag: noindex, follow`. It retains the P3F.5A Inertia SEO metadata, canonical URLs and paginated unfiltered indexability. It adds focused security regression checks. **No booking code, media storage code, published-tour query scope, migrations, routes, seat logic, payments, account permissions or TSX visual layout is changed.**

Do not confuse this milestone with site-wide SEO completion: the static bablys.club site still needs content migration, redirect mapping, sitemap/robots planning, public hosting and crawler verification before launch.

## Automated acceptance gates

Use a clean, isolated disposable MySQL database (`bablys_suite_clean_test`), with `APP_ENV=testing`, `DB_CONNECTION=mysql`, `BABLYS_REAL_MYSQL_RACE` unset; confirm `DB::connection()->getDatabaseName()` before execution. Do **not** run ordinary PHPUnit on `bablys_race_test` after concurrency fixtures, and never run on `bablys_travel`.

```
php artisan test --filter=PublicTourSeoSecurityClosureTest --stop-on-failure
php artisan test --filter=PublicTourSeoMetadataTest
php artisan test --filter=PublicDepartureEnquiryContextTest
php artisan test --filter=PublicTourDetailExperienceTest
php artisan test --filter=PublicBookingEnquiryJourneyTest
php artisan test
```

In a **different normal development shell** run:

```
vendor\bin\phpstan analyse
npm.cmd run types:check
npm.cmd run build
```

The dedicated two-worker MySQL race suite remains separate: only run with the safety opt-in against the distinct `bablys_race_test` database when needed.

## Manual browser and crawler sign-off (not automated)

- [ ] Open `/tours`, `/tours?page=2` (when enough tours), `/tours?q=...`, category/destination filters and `?sort=title_asc`. Inspect **actual HTTP response headers** and document canonical/robots tags. Filtered/search/sort responses should include `X-Robots-Tag: noindex, follow`; base and unfiltered numbered pages should have their self-canonical indexable Inertia metadata.
- [ ] Verify `<title>`, description, canonical, OG and Twitter tags in **rendered page source as seen by a crawler**. Inertia Head tags may require SSR/pre-rendering for non-JS crawlers; HTTP `X-Robots-Tag` protects filtered URLs but does **not** by itself make canonical and metadata server-rendered. Do not mark SSR/SEO crawler readiness complete until verified.
- [ ] Verify `APP_URL` matches final intended HTTPS host; audit canonical host and trailing slash consistency, SSL and robots.txt. Do **not** launch while old bablys.club and new site are both publicly accessible without a duplicate-content/301 migration plan.
- [ ] Check draft/scheduled/archived or taxonomy-ineligible tour detail URLs return 404; no hidden tour details appear in search or social previews.
- [ ] Inspect public listing/detail JSON and Inertia page source for seat counts, internal departure notes, media disk/path, booking refs, private contact fields, payment state, raw guest verification/access tokens. Do not publish sensitive values, even in unused props.
- [ ] Test private media and missing image fallback; only public-eligible JPEG/PNG/WebP/AVIF assets must appear. Check broken links and metadata images use real accessible public URLs.
- [ ] Test guest malformed/stale/cross-tour `departure_at` hints: must not preselect or reserve seats; booking quotes and submissions must be server-authoritative.
- [ ] Verify quote responses are private/no-store and guest secrets only travel via intended email fragment links, never query strings, redirects, logs, JSON props or analytics.
- [ ] At 320/375/390/768/1024/1440 widths, 200% zoom, keyboard-only and screen reader, check listing, detail, gallery and enquiry. All public screens must have **Bablys public header/footer with NO Laravel dashboard/sidebar**.
- [ ] Re-test cookies, CSRF, authentication and rate limits in the deployed environment. Do not infer host/security header controls from source-contract tests alone.

## Release gate record

| Gate | Expected | Result |
| --- | --- | --- |
| P3F.5C focused suite | All pass | Pending local execution |
| P3F.5A + P3F.4B regressions | All pass | Pending local execution |
| Full Laravel suite | No unexpected failures | Pending local execution |
| PHPStan / tsc / build | Green | Pending local execution |
| Browser, accessibility and privacy | Manual sign-off | Pending |
| Crawler/SSR canonical and host | Verified in deployment | Pending |

P3F.5C only closes after these gates are reviewed. P3G remains the later **cross-module public tour integration, security and regression closure** milestone.
