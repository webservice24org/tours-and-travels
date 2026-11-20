# Bablys Tours & Travels — P3B.6 Taxonomy Integration, Security & Regression QA

**Project:** `C:\xampp\htdocs\bablys-travel` — dedicated Laravel 13 + Inertia React TSX CMS.  
**Scope:** P3A and P3B.1–P3B.5 (tour categories, destinations, RBAC, audit, shared admin navigation).  
**Type:** Additive *tests and documentation only*. No application code, migrations, controller changes, table changes or seed changes.

## Acceptance coverage

### Group 1: `CmsTaxonomyEndToEndJourneyTest` (6 tests)
- Staff with `taxonomy.manage` opens both Inertia pages and sees their navigation links.
- Category/destination creations are attributed to the real authenticated actor in the audit log.
- Publication makes only approved published content available to the builder-ready `PublishedTaxonomyCatalog` service.
- List filters operate independently across category hierarchy and destination country.
- GPS coordinate strings survive round-trip and both coordinate values can be cleared together.
- Changed text bodies remain excluded from audit metadata.

### Group 2: `CmsTaxonomySecurityBoundaryTest` (6 tests)
- Guests are redirected from pages and denied JSON APIs.
- Logged-in ordinary customers cannot reach taxonomy pages or APIs.
- Staff without taxonomy grants cannot create taxonomy records or discover protected links.
- Revoking the `taxonomy.manage` role permission denies navigation, pages, and API calls without requiring re-login.
- Suspending an editor denies the same surfaces immediately.
- Category and destination named routes retain authentication, staff, and permission middleware.

### Group 3: `CmsTaxonomyIntegrityRegressionTest` (6 tests)
- Published children cannot be created underneath draft parents; no partial write or misleading audit event.
- Circular reparenting fails without altering the category tree.
- Soft-deleted category/destination slugs cannot be reused without redirect management.
- Invalid or half-supplied destination coordinates cannot partially modify records.
- Published destinations must be archived before deletion and disappear from public catalog reads.
- No-op category/destination edits do not emit extra audit entries.

## Automated acceptance gates

> **Database safety:** Set `DB_CONNECTION` and `DB_DATABASE` for testing to a dedicated **disposable** testing database (`.env.testing` / `phpunit.xml`). Do not run `RefreshDatabase` tests against your real development or production database.

```powershell
cd C:\xampp\htdocs\bablys-travel

php artisan test --filter=CmsTaxonomyEndToEndJourneyTest
php artisan test --filter=CmsTaxonomySecurityBoundaryTest
php artisan test --filter=CmsTaxonomyIntegrityRegressionTest

php artisan test
vendor\bin\phpstan analyse
npm run types:check
npm run build
php artisan bablys:audit:readiness
```

Run `php artisan route:list --path=admin -v` to verify routes and middleware.

## Manual browser acceptance

Perform on a non-production environment with test staff accounts:

- [ ] Super Administrator reaches `/admin`, `/admin/tour-categories`, `/admin/destinations`.
- [ ] Content Editor sees Tour Categories and Destinations links; cannot see owner-only Staff Users, Roles & Permissions, or Audit Logs links.
- [ ] Create a parent category and subcategory; publish the parent before its child. Confirm error handling for invalid hierarchy.
- [ ] Search/filter/paginate category and destination lists, including narrow/mobile layout.
- [ ] Create an international destination and filter by its country code.
- [ ] Enter a valid latitude/longitude pair, edit it, then clear both values.
- [ ] Archive then soft-delete a published destination; confirm the route no longer retrieves it.
- [ ] Verify category/destination create/edit/delete entries in `/admin/audit-logs` and confirm text bodies are not exposed in audit metadata.
- [ ] Remove `taxonomy.manage` from a test editor role, confirm both menu links and actual access disappear immediately, then restore it.
- [ ] Confirm homepage, customer dashboard, registration/login, staff user management, roles, and audit viewer still work.

## Release decision

P3B.6 may be marked **locally completed** when the three new test classes, full existing regression suite, PHPStan, TypeScript checks, build and browser checks are all green. This is **not** a production go-live authorization. Production-specific backup, access, retention and audit key controls continue to require separate sign-off.

## P3C handoff (next milestone)

- Keep `travel_tour_categories` and `travel_destinations` as the authoritative taxonomy sources.
- Define `travel_tours` and category/destination relations in a new additive domain/migration milestone.
- Preserve published-only frontend data sourcing, slug reservation and audit integrity.
- Plan reusable category/destination selectors for the tour admin form and future page-builder blocks.
- Do not expose unapproved draft content in frontend routes or dynamic builder queries.
