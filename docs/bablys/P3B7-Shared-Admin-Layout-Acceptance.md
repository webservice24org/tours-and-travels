# Bablys P3B.7 — Shared Admin Layout and Permission-Aware Sidebar

## Decision and reason

P3B.5 provided a permission-aware **horizontal** navigation bar repeated in six individual admin pages. That was an interim implementation, not a sustainable CMS admin shell. P3B.7 moves those pages into a single responsive `BablysAdminLayout` with a desktop sidebar and mobile drawer before P3C Tour Packages is developed.

This milestone is solely for **Bablys Tours & Travels**. It does not affect any other Laravel application.

## Files and scope

- New `resources/js/layouts/bablys-admin-layout.tsx`: shared header, desktop sidebar, mobile drawer, scroll-safe main content, skip link.
- New `resources/js/components/admin/navigation/CmsSidebarMenu.tsx`: presentational nested server navigation, active-link state.
- Surgical conversion of six Bablys admin page root elements from repeated `<main>` and `<CmsAdminNavigation />` to `<BablysAdminLayout>`.
- Update the old P3B.5 static source assertion to expect the shared layout (the other permission/cache tests remain unchanged).
- Four additional feature/source-regression assertions and optional read-only shell verifier.
- **No** migrations, routes, controllers, JSON API shape, permissions, seeders, audit writers, Fortify config, public-site changes, or third-party dependencies.

## Permission model

Only `/admin/internal/navigation` determines navigation groups. It is already protected by the P3B.5 backend. The sidebar neither computes nor elevates permissions. **Every application route remains protected by server middleware/policies.** Hiding a navigation item must never be treated as authorization.

## Browser acceptance

- [ ] `/admin` displays a persistent desktop sidebar, branded top bar and existing page content.
- [ ] `/admin/staff-users`, `/admin/roles`, `/admin/audit-logs`, `/admin/tour-categories`, `/admin/destinations` render their existing screens inside the same shell.
- [ ] Sidebar active link updates after navigation, with no nested horizontal P3B.5 menu.
- [ ] Narrow screen: menu button opens drawer; its close button, backdrop and Escape key dismiss it; a link click closes it.
- [ ] No horizontal document scroll on mobile; long tables keep their existing responsive behavior.
- [ ] Content Editor sees authorized taxonomy links but not Super Admin-only links.
- [ ] Customer/guest access remains rejected by Laravel even when a URL is typed manually.
- [ ] Super Admin can access all expected current admin modules.
- [ ] Public homepage, customer dashboard, registration, login, role management, audit viewer and category/destination CRUD still work.

## Technical acceptance

Run against an **isolated disposable testing database**:

```powershell
php artisan test --filter=CmsSharedAdminLayoutTest
php artisan test --filter=CmsAdminNavigationTest
php artisan test --filter=AdminTourCategoriesUiTest
php artisan test --filter=AdminDestinationsUiTest
php artisan test
vendor\bin\phpstan analyse
npm run types:check
npm run build
powershell -ExecutionPolicy Bypass -File .\scripts\bablys\verify-admin-shell.ps1 -ProjectPath C:\xampp\htdocs\bablys-travel
```

Any failure blocks completion. The installer is reversible using its backup directory under `storage/app/bablys-installer-backups/`.

## Going forward

Create every new CMS administrative page using `BablysAdminLayout` **from day one**, not by inserting another independent navigation component. To add a new sidebar link, update the P3B.5 backend `CmsAdminNavigation` group provider and enforce the same permission on its route. This applies to P3C Tours, Media & Galleries, Reviews, Builders, Blogs and SEO.

This shell is **not** the public-site Header/Footer Builder. Those remain later independent CMS features.
