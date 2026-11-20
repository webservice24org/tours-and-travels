# P3A acceptance and P3B handoff

## Acceptance checklist

- [ ] Dry-run additive installer with `-WhatIf` confirms no conflicts.
- [ ] Copy additive files and check PHP syntax.
- [ ] Back up Bablys MySQL database and run `php artisan migrate --pretend`.
- [ ] Apply migration; confirm both new tables and soft-delete fields.
- [ ] Use **disposable test database** for `RefreshDatabase` feature tests.
- [ ] `php artisan test --filter=TravelTaxonomy` green.
- [ ] Full `php artisan test` green (no RBAC, audit, staff, auth regression).
- [ ] PHPStan, TypeScript type checks and production Vite build green.
- [ ] Existing `/`, `/dashboard`, `/admin`, `/admin/staff-users`, `/admin/roles`, `/admin/audit-logs` unaffected.
- [ ] Check P2B audit readiness after migrations.

## What P3A does not deliver

No admin CRUD UI, admin/public taxonomy routes, tour models, category/destination media associations, automatic SEO, uploads, seeding of real tour content, or public page-builder blocks. Those are independent, later milestones.

## P3B task order

P3B.1 Authorize taxonomy-management routes and requests; P3B.2 Category CRUD backend + audit; P3B.3 Destination CRUD backend + audit; P3B.4 React TSX admin listings/forms; P3B.5 Integration/security/regression QA.

**Deployment reminder:** P2B local acceptance does not supersede outstanding production database/backup/key/retention sign-offs.
