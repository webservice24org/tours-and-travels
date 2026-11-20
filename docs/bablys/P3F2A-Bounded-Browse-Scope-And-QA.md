# P3F.2A — Search, Sorting and Bounded Pagination

## Intent

Incremental upgrade of the user-tested P3F.1 public `/tours` page for **Bablys Tours & Travels only** (Laravel 13, Inertia React TSX).

### Delivered

- Case-insensitive keyword matching against **already-public** tour title and summary.
- Retains validated category/destination slug filters; rejects array/non-scalar/oversized queries.
- Explicit sort whitelist: featured first, title A–Z, duration shortest/longest.
- Server-returned 12-item pages, stable ordering with slug/id tie breakers.
- Accessible, 320px-friendly search form, pagination controls with preserved filters, empty state and focus styles.
- Public Inertia response remains whitelisted by existing `PublishedTourCatalog`.
- **No migrations, no booking/payment mutations, no seat availability/pricing claims, no detail route.**

### Important bounded pagination contract

Controller requests up to 240 rows from **existing** PublishedTourCatalog, which may internally impose a **smaller cap**. It filters/sorts/paginates within *that returned catalog window*. It does **not** pretend that `total_in_window` is the site-wide tour count or that page links traverse the entire database. This is a deliberate stopgap until the catalog's source and its database filters can be inspected for **P3F.2B true SQL pagination**.

Do not use this implementation for a tour inventory exceeding the catalog window. Do not remove publication safety filters, duplicate them blindly in a new SQL query or expose a `total` claiming all database rows.

### Exact version compatibility

Installer compares exact SHA256 of the two installed **P3F.1** files before replacing them. If either differs, it aborts without changes, preserving local work for manual review. It makes timestamped backups for replacements and rolls back new files on failure. It never touches `routes/web.php`, route registrations, DB migrations or other app files.

### Manual QA

1. Verify you are in a testing CMD using `testing | bablys_suite_clean_test` and `BABLYS_REAL_MYSQL_RACE` unset.
2. `php artisan test --filter=PublicTourBrowseEnhancementTest` (7 tests); `php artisan test --filter=PublicTourDiscoveryFoundationTest` (5 pre-existing P3F.1 tests); then full `php artisan test`.
3. Normal CMD: `vendor\\bin\\phpstan analyse`, `npm.cmd run types:check`, `npm.cmd run build`.
4. Browser: `/tours`; keyword search, case-insensitivity, sorts, category/destination combination, pagination after 12 rows, previous/next link retention, empty result, keyboard/focus and mobile width 320/375px.
5. In the Inertia JSON payload, verify draft/scheduled/archived tours absent and no price, seat ledger, booking secrets or private contacts exposed.
6. P3F.2B will depend on reviewing `app/Domain/Travel/Tours/PublishedTourCatalog.php` and corresponding public taxonomy/media read models before introducing DB pagination and media. Do not mark full P3F.2 complete based only on P3F.2A.
