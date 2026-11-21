# P4A.2 — Navigation menu drafts: acceptance and security checklist

## Scope

- Admin: `/admin/navigation-menus` and `/admin/navigation-menus/{id}`. Login, active CMS staff and `settings.manage` required.
- Public read API: `/api/public/navigation-menus` returns **published-only** named navigation snapshots, currently `{ "menus": {} }` until P4A.3 publication.
- Menu keys are stable identifiers. Header/footer placement and publication are future P4A.3/P4A.4 milestones. **Do not claim this changes the live website menu today.**
- No code injection, raw HTML, JavaScript URLs, mailto, data or HTTP external links; only local `/path` and external HTTPS.
- External new-tab links must be rendered with `rel="noopener noreferrer"` in P4A.4.
- Up to 80 items/menu, root plus two nested levels (three total), parent references must remain within one menu.

## Editor manual QA

1. Sign in as an authorized CMS settings manager; navigate to `/admin/navigation-menus`.
2. Create `main-header` and `footer-links`, reject an existing duplicate key.
3. Edit menu label, add internal `/tours` and `/destinations` links, create child and grandchild; save draft and reload.
4. Reorder siblings with Up/Down. Edit destination and move parent; invalid cycles must be rejected with validation errors.
5. Add an external HTTPS link, choose new-tab; reject `javascript:`, `http:`, backslash, protocol-relative, and credential-bearing links.
6. Save from two browser tabs: stale editor should see version error and not overwrite newest draft.
7. With menu drafts present, open `/api/public/navigation-menus` in another browser: no unpublished links or editor metadata should leak.
8. Delete only an unpublished menu, confirm it and revisions disappear. Published menu deletion is blocked.
9. Test keyboard and 200% browser zoom; no horizontal overflow, labels visible, focusable controls.
10. Test a customer and a staff member without `settings.manage` cannot access the admin routes or mutate menu data.
11. Re-run all booking/public tour/regression tests. No changes should occur in those modules.

## Acceptance boundary

P4A.2 closes when tests, PHPStan, TS check, production build and manual editor/security checks pass. P4A.3 will implement an authorized, auditable publish/preview/rollback workflow. P4A.4 will connect published menu placement to the site's existing sidebar-free public header/footer, with mobile handling and cache invalidation. Do not use an unsafe draft-only menu to replace the current live navigation.
