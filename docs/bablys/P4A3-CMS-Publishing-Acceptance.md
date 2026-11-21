# P4A.3 — CMS Publication, Content Preview & Rollback — Acceptance

## Safety and permissions
- Only active CMS staff granted `settings.manage` can access `/admin/cms-publishing` or perform POST actions.
- No GET routes publish, mutate, or restore data. CSRF is enforced through Laravel's existing web middleware.
- Admin previews are private + `noindex, nofollow`; drafts never appear in either `/api/public/site-settings` or `/api/public/navigation-menus`.
- Stale editors must not publish/restore when the draft version OR publication serial changed.
- All publication operations lock their rows and write an immutable event within one transaction. Actor ID, event serial and source draft version are recorded.
- Rollback may only restore an earlier **published** snapshot for the same resource. It must never publish arbitrary, unapproved draft history.

## Owner test sequence
1. Save a draft settings change in `/admin/site-settings`. Verify the public settings API is unchanged.
2. In `/admin/cms-publishing`, open settings Preview. Inspect draft-vs-published values; press Publish. Verify public settings API returns the published value.
3. Save another settings draft but do not publish. Verify public API still shows last published value.
4. Publish another revision, then restore the previous publication. Verify public API reverts and the latest draft remains intact.
5. Create a menu in `/admin/navigation-menus`, add nested links and save draft. Verify menu public API has no unpublished link.
6. Publish the menu from the publishing center. Verify `menus.main-header` shows only the approved published links.
7. Change the menu draft and verify public API unchanged; publish and restore a previous version. Verify the old menu returns publicly and the private draft stays as saved.
8. Attempt publishing with a stale preview tab; it must fail with a version conflict and write no new publication event.
9. Verify published menus cannot be deleted by the draft menu deletion endpoint.
10. Verify a non-privileged content editor and a customer cannot preview or perform any publish/restore action.

## Release boundary
- P4A.3 adds **content** previews, not the eventual final visual website rendering.
- The public header/footer and actual live navigation integration is **P4A.4**. Do not claim editing the menu updates the header yet.
- The P4A.1 public settings API advertises a browser cache lifetime of up to 60 seconds; inspect an uncached response (or allow cache expiry) when verifying immediate changes. P4A.4 will coordinate final frontend caching/invalidation.
- Logo/favicon uploads and visual header/footer builders are later workstreams.
- No booking or payment data is touched.

## Local gates
- Use `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_DATABASE=bablys_suite_clean_test` only in the **dedicated test CMD**. Confirm via artisan tinker before running any test.
- Focused `php artisan test --filter=CmsPublishingPreviewRollbackTest --stop-on-failure`, then existing P4A.1/P4A.2 tests and full suite.
- Separate normal-development CMD: `vendor\\bin\\phpstan analyse`, `npm.cmd run types:check`, `npm.cmd run build`.
- Verify routes and manually check 403, 422, duplicate publish, 404 for cross-resource restoration, private cache and public API exposure.
