# P3F.2B.1 — True SQL Pagination and Published Taxonomy Suggestions

## Scope and safety

- **Full-inventory pagination**: `PublishedTourCatalog::browse()` performs publication-scoped, parameter-bound SQL search, deterministic sorting and a database count; 12 tours per page, without the historical 50-row `tours()` limit.
- **Legacy contract preserved**: `PublishedTourCatalog::tours()` retains its signature, public field allowlist, `ordered()` scope, 1–50 limit, and publication/association rules.
- **Taxonomy suggestions**: Published `TourCategory` and `Destination` models provide up to 200 names/slugs each. Draft/archived/deleted entries are not suggested. A user can still type any valid slug; the underlying filter is not suggestion-capped. Published-but-unlinked taxonomy options may be shown and yield no tours.
- **Privacy**: only id, title, slug, summary, duration_days and is_featured are projected for tour cards. Do not expose prices, unpublished media, capacity or booking state.
- **Search escaping**: `%`, `_`, `!` are literal search characters. Filter values are validated before use and SQL values are bound.
- **Pagination**: request pages must be positive decimal values, maximum five digits; missing out-of-range pages return 404. Empty search page 1 returns 200 with zero items.
- **No media in this increment**: a safe cover image requires the project's actual media model/migration/storage/publication contract. Do not guess a storage URL or expose a private asset. See next step P3F.2B.2.

## Local QA checklist

1. On dedicated MySQL test DB `bablys_suite_clean_test` confirm `APP_ENV=testing`, opt-out of `BABLYS_REAL_MYSQL_RACE`, and verify effective connection before tests.
2. `php artisan test --filter=PublicTourSqlPaginationTest` (9 tests); then prior `PublicTourBrowseEnhancementTest` and `PublicTourDiscoveryFoundationTest`; then complete suite.
3. In normal dev CMD run `vendor\\bin\\phpstan analyse`, `npm.cmd run types:check`, `npm.cmd run build`.
4. Browser-check `/tours`, `?q=`, category/destination typed suggestion list, combined filters with page 2, sort ordering, 320px/375px widths, keyboard use, dark text contrast and published-only results.
5. Check SEO/no-pricing/no-seat claims. No new route, migrations, courier or booking logic.
6. Once all QA passes, request `database/migrations/*create_travel_tour_media*`, the TourMedia model (if present), and media storage/public URL policy to implement P3F.2B.2 safely.
