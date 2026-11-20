# Bablys P3D.2 — Tour Editor Acceptance & Security

**Project:** Bablys Tours & Travels only, Laravel 13 / Inertia React TSX.

## Backend consumed (unchanged)

- GET `/admin/internal/tours` — pagination/filter/search
- GET `/admin/internal/tours/taxonomy-options` — first 200 nonarchived choices each
- GET `/admin/internal/tours/{tour}` — detail and pivot IDs
- POST `/admin/internal/tours` — create tour
- PUT `/admin/internal/tours/{tour}` — edit tour
- DELETE `/admin/internal/tours/{tour}` — audited soft-delete (not published/scheduled)

P3D.1 middleware and transaction-bound gates are the sole authority for permissions. Inertia `can` props only improve controls; they are **not** authorization. Tour slugs are immutable. Live/scheduled tours require `tours.publish` to edit. Published tour creation requires published eligible taxonomy. `scheduled_for` is entered as local time, submitted as UTC ISO. **Scheduled tours remain private** until a separate scheduling activation service exists.

## React/UX

- Tour directory: title/slug, featured badge, status, duration, taxonomy counts, search, filters, page size and pagination; mobile cards
- Tour editor: create draft, edit body, duration, featured, sort order, category/destination checkbox multiselect, status and optional scheduling
- Published/scheduled entries: editors without publish privilege cannot modify them; backend independently enforces on mutation
- Shared AdminFeedbackHost: `confirmAction` for soft-deletes, `toast.success` / `toast.error` for mutations; inline field-level 422 errors
- Only existing Laravel AppSidebar and Bablys content wrapper; no nested sidebar, no app navigation duplication
- Permission-filtered `/admin/internal/navigation` includes `/admin/tours` only with `tours.view` permission
- No tour pricing, departure dates, inventory, public detail routes, media, WYSIWYG builder or AI SEO yet

## Constraints / follow-up

The taxonomy options endpoint caps results at **200 categories and 200 destinations**. Existing assigned IDs outside the first 200 remain preserved in the editor and display an explicit warning; a server-searchable paginated selector belongs to P3D.3, before onboarding larger catalogs. Local filter options also show the first 200; no claim is made of exhaustive filtering in large catalogs.

## Acceptance in browser

1. Log in as Super Administrator. Confirm **one** native Laravel sidebar and **Tour packages** link.
2. Visit `/admin/tours`; create a draft with a unique slug, duration, categories and destinations. Verify success toast.
3. Edit summary, description, statuses and category/destination assignments. Verify persistent DB-backed values after refresh.
4. Publish only after selecting eligible *published* category ancestry and published destination. Verify invalid attempts show field-level validation.
5. Schedule a future tour. Verify it stays private (no automatic publishing in this phase).
6. Attempt to delete a published tour. UI must disable action; backend must reject. Archive/unpublish first, then delete via the **shared** confirm dialog; Cancel must not mutate.
7. Log in as Content Editor. Verify draft editing but no publication controls, and existing published/scheduled tours are not editable.
8. Log in as an unrelated staff role. Verify no Tour Packages link or page access.
9. Verify mobile-width screen has no horizontal overflow and the existing Laravel sidebar drawer handles navigation.
10. Confirm latest audit events and run all tests on a **separate disposable testing database**.

## Quality gates

```powershell
php artisan route:clear
php artisan route:list --path=admin -v
php artisan test --filter=AdminToursUiTest
php artisan test --filter=AdminTourManagementBackendTest
php artisan test
vendor\bin\phpstan analyse
npm run types:check
npm run build
php artisan bablys:audit:readiness
```

Local green checks mean code acceptance, **not** operational production signoff: DB least privilege, offsite encrypted backup/restore, key governance, retention/legal holds are separately outstanding.
