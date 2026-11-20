# P3D.1 — Local acceptance

1. Extract ZIP outside `C:\xampp\htdocs\bablys-travel`.
2. Backup current project and database. Confirm `.env.testing` / `phpunit.xml` uses a disposable test database.
3. Run the installer with `-WhatIf`; review **new files only** and a single `routes/bablys_admin.php` include.
4. Run installer. No migrations or UI modifications.
5. `php artisan route:clear`
6. `php artisan route:list --path=admin -v` must show the six new endpoints with auth/CMS middleware.
7. `php artisan test --filter=AdminTourManagementBackendTest`
8. `php artisan test` — whole suite green.
9. `vendor\bin\phpstan analyse` — zero errors.
10. `npm run types:check` and `npm run build` — green (existing frontend unchanged).
11. `php artisan bablys:audit:readiness` — green.
12. Open `/admin`, `/admin/tour-categories`, `/admin/destinations`, `/admin/audit-logs`, `/dashboard`, `/` — existing browser experience unchanged.
13. Verify publication protection and audit records from the dedicated tests; P3D.2 provides browser tour CRUD.

**If any failure occurs:** share the exact output, do not force overwrite, do not disable PHPStan, do not reset real data, and don't assign new roles implicitly.
