# P3E.1 — Local Acceptance and P3E.2 Handoff

## Preflight

- Back up the Bablys database and source tree.
- Verify migration status for P3C and P3D.3, plus P3D.4 tests.
- Use a **separate disposable testing database** for RefreshDatabase tests; do not run the tests against your working `bablys_travel` database.
- Extract the package outside the Laravel project. Run PowerShell installer in `-WhatIf` first; expected: one migration, one model, two domain classes, three test files, and two documentation files (nine new files total).

## Acceptance commands

```powershell
cd C:\xampp\htdocs\bablys-travel
php artisan migrate:status
php artisan migrate --pretend
php artisan migrate
php artisan test --filter=TourItinerarySchemaTest
php artisan test --filter=TourItineraryRulesTest
php artisan test --filter=TourItineraryManagerTest
php artisan test
vendor\bin\phpstan analyse
npm.cmd run types:check
npm.cmd run build
php artisan bablys:audit:readiness
```

## Assertions

- Migration creates `travel_tour_itinerary_days` without modifying existing tour or media tables.
- Unique (tour, day) prevents double days. Hard-delete cascades, soft-delete retains associated days.
- Rule normalization permits an empty or partial draft itinerary, numbers 1…N, canonicalizes meal order and checks field bounds and plain-text safety.
- Edit permission is revalidated inside a transaction. Published and scheduled tour updates also require publish permission.
- No-op replacement produces no repeated audit event; invalid replacement rolls back and preserves existing days.
- Audit metadata does not leak titles or descriptions.
- Tours UI and native sidebar remain unchanged; **the itinerary UI is P3E.2**, not part of P3E.1.

## Production gate

Local test completion is not approval to deploy. Validate DB backup/restore, migration rollout, permissions and audit retention under the Bablys production operational checklist. Do not mark P3E.1 complete until all local checks pass.
