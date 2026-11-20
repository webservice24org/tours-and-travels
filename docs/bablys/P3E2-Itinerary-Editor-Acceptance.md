# P3E.2 — Daily Itinerary Editor Acceptance

## Scope

Extends the **existing** Bablys full-page `/admin/tours/{id}/edit` React TSX page. It renders an itinerary section in the left `lg:col-span-9` main column, not a separate admin layout. Uses the original Laravel `AppSidebar`, common `confirmAction`/`toast` host, TourItineraryManager P3E.1 contract and existing authentication, permissions and audit trail. No migrations.

## Workflow

1. Log in as a content editor. Open an existing draft of duration 3 days.
2. In **Daily Itinerary**, click **Add itinerary day**. Enter title, location, overnight stay, plain-text description, meals and highlights. Add Day 2; save. Confirm success toast and data persisted after reload.
3. Edit a day, move Day 2 above Day 1 (the content changes positions; day numbers become consecutive), duplicate a day if capacity allows, then Save Itinerary. Confirm order after reload.
4. Remove a day: the shared Confirm Dialog should open; Cancel should leave the day unchanged. Confirm should remove it in the editor; then Save Itinerary to persist deletion.
5. Add days until 3; **Add** and **Copy** should disable at capacity. Verify no days beyond tour duration can be saved through HTTP either.
6. Change the parent tour duration from 3 to 2 without first removing Day 3: **Save Tour** must fail with an inline `duration_days` validation error, and the existing tour/day rows must remain unchanged. Remove Day 3 and **Save Itinerary**, then save parent Tour duration 2 successfully.
7. On `/admin/tours/create`, the itinerary section tells you to save the new tour first. No uploaded or unsaved parent ID is assumed.
8. On a published/scheduled tour, a content editor without `tours.publish` must not write itinerary days, even if they send the PUT manually. Authorized administrators may write.
9. Check browser widths 375px, tablet, desktop. No horizontal overflow, no second sidebar. All itinerary actions use `type="button"` so clicking them doesn't submit the parent Tour form.
10. Verify only audit metadata (changed positions/day count), not day titles and descriptions, are saved in `cms_audit_events`.
11. Logout and verify JSON endpoints return unauthorized/forbidden responses. Disable/revoke a staff role and verify subsequent writes fail.

## Endpoints

- GET `/admin/internal/tours/{tour}/itinerary` — `{ data: TourDay[], meta: { duration_days, status } }`.
- PUT `/admin/internal/tours/{tour}/itinerary` — body `{ days: TourDay[] }`, same normalized JSON response; empty list allowed; no price or availability mutation.

Both endpoints are private/no-store and protected by Laravel session, `EnsureCmsStaff`, `tours.view` or `tours.edit`, plus domain-level authorization. Live/scheduled tours require `tours.publish` to edit.

## Known boundaries

- Descriptions are **plain text** in P3E.2, consistent with P3E.1 sanitizer; tour long-description Tiptap remains untouched.
- The itinerary does not support public exposure, prices, departure dates, capacities or booking inventory. Those are later milestones.
- Save Itinerary is independent of Save Tour. The UI blocks saving itinerary while the parent tour duration has unsaved changes; server-side lock/revalidation is authoritative.
- Changes made in another browser tab are not merged automatically. Refresh before saving conflicting edits; future workflow versioning may be introduced.

## Regression commands

```powershell
php artisan test --filter=AdminTourItineraryUiTest
php artisan test --filter=TourItineraryManagerTest
php artisan test --filter=AdminTourManagementBackendTest
php artisan test
vendor\bin\phpstan analyse
npm.cmd run types:check
npm.cmd run build
```

Use a **disposable test database** for `RefreshDatabase` test suites.
