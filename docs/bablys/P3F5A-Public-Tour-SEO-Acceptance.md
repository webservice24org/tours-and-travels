# P3F.5A — Public Tour SEO & Canonical QA

## Automated gates
- Use only `bablys_suite_clean_test` with `APP_ENV=testing`; clear `BABLYS_REAL_MYSQL_RACE`.
- `php artisan test --filter=PublicTourSeoMetadataTest --stop-on-failure`
- `php artisan test --filter=PublicTourSqlPaginationTest`
- `php artisan test --filter=PublicTourDetailExperienceTest`
- `php artisan test --filter=PublicDepartureEnquiryContextTest`
- `php artisan test` (3 known opt-in/disabled-feature skips may remain)
- Normal development terminal: `vendor\bin\phpstan analyse`, `npm.cmd run types:check`, `npm.cmd run build`.

## Browser and SEO acceptance
- `/tours`: view source/head, verify absolute canonical `/tours`, index/follow, title, description, OG/Twitter metadata.
- `/tours?page=2` when sufficient tours: self-canonical `/tours?page=2`, distinct title.
- `/tours?q=...`, `?category=...`, `?destination=...`, nondefault sort: canonical base `/tours`, `noindex, follow`, no untrusted query in SEO props.
- `/tours/{published-slug}`: self canonical URL, site name/OG URL/Twitter metadata, optional social image only for absolute HTTP(S) public photos.
- Draft/scheduled/archived tour remains inaccessible; do not include private booking fields or internal departure information.
- Verify sidebar-free public header/footer and keyboard access at 320, 375, 768, 1280px viewport widths; no sideways page scroll.
- Repeat focused browser checks for `/tours/{slug}/booking-request`: nonbinding wording and date preference still correct.

**Scope:** This phase only adds SEO metadata policies to existing index/detail pages. It does not submit sitemaps, change canonical host config, generate structured data, change robots.txt, create booking or financial data, or implement site-wide SEO for unrelated pages. P3F.5B will focus on accessibility and mobile audits.
