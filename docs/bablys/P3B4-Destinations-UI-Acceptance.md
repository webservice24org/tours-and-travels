# Bablys Tours & Travels — P3B.4 Destination Management UI Acceptance

## Purpose

An additive, permission-protected React TSX administration interface at `/admin/destinations` built over the already tested P3B.2 JSON API at `/admin/internal/destinations`. This is **Bablys only**; never mix with the news, e-commerce or ERP applications.

## What is included

- Destination list with responsive table/cards, search by name/slug/city/region, country ISO two-letter filter, published state and GPS-present filters, pagination (10/20/50).
- Create/edit drawer with name, suggested editable-on-create slug, country code, region, city, summary, description, latitude/longitude pair, publishing status and sort order.
- Slug is read-only on edit; server still enforces slug immutability and reserved slugs.
- Deletion uses confirmation and soft-delete; published destinations must first be archived. The backend remains the authority.
- Laravel validation errors are shown near fields. JSON client uses same-origin web session/XSRF, returns useful 401/403/419 messages, cancels stale list requests.
- New page route has `auth`, `EnsureCmsStaff`, and `EnsureCmsPermission:taxonomy.manage`; controller repeats Gate authorization.
- Existing P3B.2 mutation and audit logic is reused unchanged. No migration, data seeding, API changes, admin-navigation changes or public page changes.

## Browser QA checklist

1. Sign in as an authorized Bablys `content_editor`, open `/admin/destinations`, verify responsive desktop/tablet/mobile layout. Check the "Back to admin" and "Tour categories" links.
2. Create a **Bangladesh** destination, e.g., `Coxs Bazar` / `coxs-bazar` / country `BD`, status `draft`, with `21.4272, 92.0058`. Check it appears in the directory, with coordinates and an audit event.
3. Create another destination for country `TH`. Search and filter by `BD`, by status and by "with coordinates"; verify pagination and reset filters.
4. Edit region, city and description, try changing a slug (UI locked); audit history should show edited fields.
5. Clear both coordinates and save; verify both are null. Try supplying only latitude; see client/server validation. Try out-of-range coordinates; server must reject with 422.
6. Publish the draft. Confirm **Delete** is disabled. Edit status to **Archived**, save, then confirm soft-delete works. The deleted slug remains reserved.
7. Verify Guest -> login, ordinary customer -> 403, unverified/suspended staff -> 403, and an unrelated staff role -> 403. Existing API access checks must remain enforced.
8. Verify `/`, `/admin`, `/admin/tour-categories`, `/admin/staff-users`, `/admin/roles`, `/admin/audit-logs`, login and customer dashboard continue to work.

## Local gates (must run on a disposable testing DB)

```powershell
php artisan route:clear
php artisan route:list --path=admin -v
php artisan test --filter=AdminDestinationsUiTest
php artisan test --filter=AdminDestinationCrudTest
php artisan test --filter=AdminTourCategoriesUiTest
php artisan test
vendor\bin\phpstan analyse
npm run types:check
npm run build
php artisan bablys:audit:readiness
```

**Do not mark P3B.4 complete until all local gates and browser QA pass.** Admin navigation/sidebar integration is explicitly reserved for P3B.5; category & destination backends are unchanged.
