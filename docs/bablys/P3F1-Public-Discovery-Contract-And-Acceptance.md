# P3F.1 — Public Tour Discovery Contract and Acceptance

## Scope

This is the first **increment** of **P3F — Dynamic Public Tour Listings & Details** for the **Bablys Tours & Travels Laravel 13 / Inertia React TSX** project only.

- Public `/tours` list route and responsive Inertia page.
- Delegates approved catalog selection and field whitelisting to existing `App\Domain\Travel\Tours\PublishedTourCatalog` from P3C/P3D.
- Slug-only category/destination query filtering, fixed maximum 24 results; no sorting/pagination claims.
- No migrations, no prices, no availability claims, no booking/payment mutations.
- Drafts, unpublished or category/destination-invalid tours remain excluded by the existing published catalog service.

## Explicit deferrals

- P3F.2: true server-side pagination, selectable taxonomy filters, thumbnail/media presentation, search/order UI.
- P3F.3: public tour detail route and safe itinerary, price and departure read models.
- P3F.4: category/destination discovery pages and nonbinding booking-request CTA based on readiness.
- P3F.5/P3G: frontend SEO metadata/canonical/schema, browser/a11y/security and integration closure.

## Manual route registration (deliberately not auto-patched)

1. Run `php artisan route:list --path=tours` to check for an existing `/tours` GET route. Stop if it already exists; do not override it.
2. Open `routes/web.php` and add exactly once, *within the PHP source*: `require __DIR__.'/bablys_public_tours.php';`
3. Do not remove or reorder existing booking-request routes.
4. Run `php artisan route:clear`, then `php artisan route:list --path=tours` to confirm `GET /tours` named `public.tours.index`.

## QA acceptance

1. Use disposable, isolated `bablys_suite_clean_test` MySQL and `APP_ENV=testing`, **not** development DB `bablys_travel` and **not** race DB `bablys_race_test`.
2. `php artisan test --filter=PublicTourDiscoveryFoundationTest` must pass without skips.
3. `php artisan test`, `vendor\bin\phpstan analyse`, `npm.cmd run types:check`, `npm.cmd run build` must pass without unexpected failures.
4. In a normal development browser, `/tours` shows only published tours, empty state works, filters and clear filters work, focus is visible, no horizontal scrollbar at 320 px.
5. Verify no pricing, capacity, guest token, or private contact data appears in public Inertia props or source HTML.
6. **P3E.6 manual browser/security sign-off remains separate**; its automation results do not certify that checklist.

## Notes

- The existing published catalog may have a bounded result limit, so 24 is a maximum, not guaranteed complete coverage. No pagination is offered in P3F.1.
- Media thumbnails are intentionally deferred until a grounded public media data contract is available.
- This listing is informational; it does not reserve seats or create a binding quote.
