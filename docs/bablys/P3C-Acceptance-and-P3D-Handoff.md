# P3C acceptance and next-phase handoff

## Local acceptance checklist

- [ ] Extract ZIP **outside** the Laravel project; `-WhatIf` preview shows only planned files.
- [ ] Back up the `bablys_travel` MySQL database.
- [ ] Confirm `.env.testing` / `phpunit.xml` targets a **disposable** test database, not the real or production one.
- [ ] `php artisan migrate:status` and `php artisan migrate --pretend` review: exactly three new tables.
- [ ] Run `php artisan migrate`; confirm tables, unique slug, FK constraints and soft-delete field.
- [ ] Run `php artisan test --filter=TravelTourSchemaTest`.
- [ ] Run `php artisan test --filter=TravelTourDomainTest`.
- [ ] Run `php artisan test --filter=TravelTourPublishedCatalogTest`.
- [ ] Full `php artisan test` passes, including P2B audit/security and P3B taxonomy tests.
- [ ] `vendor\\bin\\phpstan analyse` passes without suppressions.
- [ ] `npm run types:check` and `npm run build` pass.
- [ ] `php artisan bablys:audit:readiness` remains green.
- [ ] `/`, `/dashboard`, `/admin`, `/admin/tour-categories`, `/admin/destinations`, `/admin/roles`, `/admin/audit-logs` remain functional.
- [ ] One native Laravel admin sidebar remains; existing confirm dialog and toast utilities remain unchanged.

## How many new tests?

- `TravelTourSchemaTest`: **7**
- `TravelTourDomainTest`: **8**
- `TravelTourPublishedCatalogTest`: **6**
- Total: **21** focused feature tests.

## Handoff to P3D

Build secure Tour CRUD and React TSX UI *on top of this foundation*:

1. `tours.*` server-side gate/policy checks at route, Form Request and domain mutation boundaries.
2. Admin list/create/edit forms; choose **multiple categories and destinations**, preferably from published or draft taxonomy as appropriate.
3. Use `TourSlug`, `TourTaxonomyRules`, and `TourPublicationRules` within transactional operations.
4. Record tour create/edit/status/taxonomy-link changes in Bablys audit events; avoid plaintext sensitive content and no-op audit events.
5. Use the existing single `AppSidebar` admin shell and common `ConfirmDialog`/toast system (P3B.7A/P3B.8).
6. Do not expose incomplete pricing, seats, departures or itineraries before P3E.
7. Add focused authorization, validation, concurrency and regression tests.

**Production reminder:** P2B production operational sign-offs (database least privilege, encrypted off-site backups/restore drill, archive key management and retention approval) remain independent of local green checks.
