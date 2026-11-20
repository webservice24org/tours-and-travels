# P3D.4 — Tour Management Integration, Security & Regression QA

**Application:** Bablys Tours & Travels, Laravel 13 + Inertia React TSX (not the e-commerce, news, or ERP apps).

## Objective

Close the P3D tour-management implementation with reproducible tests for the P3C domain, P3D.1 backend, P3D.2 listing, P3D.3 full-page editor, tags, rich-text sanitation, and tour-scoped images. **Only test fixtures and documentation are installed**; no controller, route, migration, production model, UI, or RBAC change.

## Test groups (23 tests)

| Test class | Count | Coverage |
|---|---:|---|
| `CmsTourEndToEndJourneyTest` | 7 | Draft -> published catalog, unpublish, shared tags, safe HTML, gallery/featured cycle, full-page 9/3 editor |
| `CmsTourSecurityBoundaryTest` | 8 | Guest/customer/read-only role boundaries, publish permission, revocation, suspension, cross-tour media, invalid uploads |
| `CmsTourIntegrityRegressionTest` | 8 | Failed-publication rollback, tag validation rollback, tag idempotence, slug reservation, taxonomy withdrawal, tag limits, embedded-image deletion, audit redaction |
| **Total** | **23** | Additive P3D.4 coverage; run older test suites too |

### Preconditions

- P3D.3 v2 installed successfully; its three migrations show as **Ran**.
- `@microweb/laravel-tiptap-editor` installed from your local React package tarball; Laravel 12-only Composer backend **not force-installed**.
- P3D.3 PHPStan `TourMediaService` guard correction applied if needed.
- PHP extensions GD, DOM, fileinfo enabled. Media tests call `Storage::fake('public')` and clean up with the test database.
- **Use a disposable SQLite/MySQL testing database configured in `.env.testing` or phpunit.xml**. `RefreshDatabase` runs migrations and can destroy records in a wrongly configured database. Confirm testing connection before running suite.

### Run in Command Prompt or PowerShell

```powershell
cd C:\xampp\htdocs\bablys-travel
php artisan migrate:status
php artisan route:clear
php artisan test --filter=CmsTourEndToEndJourneyTest
php artisan test --filter=CmsTourSecurityBoundaryTest
php artisan test --filter=CmsTourIntegrityRegressionTest
php artisan test
vendor\bin\phpstan analyse
npm.cmd run types:check
npm.cmd run build
php artisan bablys:audit:readiness
```

Note: if the test runner reports **skipped** image tests because GD is absent, the media acceptance gate **is not passed**. Enable GD, rerun and check image processing tests.

### Browser signoff (manual, not claimed automated)

1. `/admin/tours` links to full-page `/admin/tours/create` and `/admin/tours/{id}/edit`; one original Laravel `AppSidebar`, no nested admin sidebar. Mobile uses stacked layout.
2. Create a draft using the 9/3 editor: title, slug, summary, MicroWeb Tiptap description, duration, categories, destinations, tags. After creation, the editor opens edit mode.
3. Type a tag already attached to another tour; autocomplete reuses its existing record, case-insensitively. Test a new tag and tag removal.
4. Upload the first image, choose another featured image, enter alt text, reorder where supported, delete a gallery image via **shared Confirm Dialog**. Confirm **shared Toast** on successful mutations.
5. Embed an uploaded tour image inside description, save, reload, attempt to delete image (must be rejected), remove image from body and save, then delete image.
6. Publish with a published category/destination as an administrator; editor must not be able to publish or edit an already published tour. Unpublish and verify it leaves `PublishedTourCatalog`.
7. Verify audit events for tour creation, tag changes, and media mutations. Check no full rich-text body or image bytes are logged.
8. Test a read-only Designer, a Content Editor, and an Administrator; only authorized links/actions appear, including after revocation or staff suspension.
9. Check existing `/admin/tour-categories`, `/admin/destinations`, staff/roles/audit pages, and `/dashboard` still work.

### Signoff notes and known future scope

- PublishedTourCatalog is a **domain data source only**; no public tour detail URL or booking mechanism is added in P3D.
- Tour itineraries, pricing, departures, availability, and booking capacity belong to P3E. **Never claim these already work.**
- Scheduled tour activation is not implemented yet; scheduled records remain private.
- Tour images live on the `public` disk; once uploaded their asset URLs are public even for draft tours. Do not upload private/customer-identifying media.
- Max 24 tour images, 5 MB upload input, 4,000 px max side, 12 MP max area. Images are GD re-encoded.
- The category/destination options API currently limits to 200 rows; server-side searchable selectors remain later UX work.
- Media file cleanup failures after DB commit can leave orphans; reconciliation belongs to general media operations.
- Production read/write authorization, audit archiving, backups, retention, and operational controls still need their own production signoff.
- The catalog currently verifies a directly published category and destination; if category ancestor publication is later withdrawn, review ancestor-aware read filtering in the public tour rendering milestone. P3C's write-side publication validator enforces published ancestors at publishing time.

### Passing criteria

All 23 new tests and all existing Laravel tests pass with no unexpected skips, PHPStan reports 0 errors, TypeScript and Vite build pass, and the browser checklist is accepted. Then mark **P3D.4** and overall **P3D** complete; begin **P3E — Itineraries, Pricing & Departures**.
