# Bablys P3B.5 — Admin Navigation & UX Hardening

## Scope

P3B.5 connects the Bablys CMS navigation across the existing admin landing, Staff Users, Roles & Permissions, Audit Logs, Tour Categories and Destinations pages. It is a **navigation and UX** step, not a new content-management system.

### Security contract

- `GET /admin/internal/navigation` is a read-only, same-origin endpoint behind `auth` and `EnsureCmsStaff`.
- Links are computed on each request from authoritative CMS database permissions.
- The Tour Categories and Destinations links require `taxonomy.manage`.
- Staff Users and Roles links require **both** `super_admin` membership and `roles.manage`.
- Audit Logs requires **both** `super_admin` membership and `audit.view`.
- Public-site and customer-dashboard shortcuts remain available to signed-in CMS staff.
- The navigation response sets `Cache-Control: private, no-store, max-age=0` to avoid caching permissions.
- A menu item **never** grants access: destination routes retain their own Laravel middleware and authorization.
- No placeholder links for future Tour packages, blog, gallery, reviews, builders or SEO are enabled yet.

### Usability contract

- Shared navigation appears on all six current admin Inertia pages.
- On narrow screens, navigation collapses behind a keyboard-usable button with `aria-expanded`/`aria-controls`.
- On wider screens, grouped navigation is visible without a menu click.
- The current page uses `aria-current="page"` and a distinctive state.
- Navigation and action areas are responsive with wrapping instead of forced horizontal body overflow.
- A failed navigation request shows a visible warning and a way back to Admin; no stored stale permission menu is displayed.

### Local acceptance checklist

1. Sign in as Super Admin: open `/admin`, then visit Staff Users, Roles, Audit Logs, Categories and Destinations via shared navigation. Check that the active page is indicated.
2. Sign in as a verified `content_editor`: both Travel content links appear; owner-only links do not.
3. Sign in as `review_moderator`: the Travel content links are absent without taxonomy permission.
4. Temporarily revoke `taxonomy.manage` in a disposable test database. A subsequent navigation request must no longer return taxonomy links, and direct access to those pages must remain forbidden.
5. Suspend a staff user: the JSON navigation endpoint must return 403 without exposing menu contents.
6. Repeat on a narrow mobile viewport and with keyboard navigation. Confirm no horizontal body overflow or unclickable controls.
7. Run `php artisan test --filter=CmsAdminNavigationTest`, full tests, PHPStan, `npm run types:check`, `npm run build` and audit readiness.
8. Verify all previous category/destination and owner modules continue to work in the browser.

### Boundaries

- No migrations, database updates, role seeding or audit-writer changes.
- No changes to P3B.1/P3B.2 API or publishing rules.
- Future site-wide visual Header/Footer builders remain separate roadmap phases.
- Production operational sign-offs from P2B.6D remain tracked independently.

### Rollback

The installer stores the changed routes file and six original TSX pages under `storage/app/bablys-installer-backups/p3b5-*`. Rollback requires restoring those backups and removing only the new package files listed by the installer. Do not remove unrelated edits made after P3B.5.
