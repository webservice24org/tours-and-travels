# P3B.3 — Tour Categories React TSX UI: engineering acceptance

## Contract and scope

- Route `/admin/tour-categories` renders Inertia component `admin/tour-categories/index` only for verified, active staff with `taxonomy.manage`.
- Uses existing P3B.1 `/admin/internal/tour-categories` JSON API, including `/options` for parent choices.
- Fields: name, immutable-on-edit slug, optional parent, summary, description, status (draft/in_review/published/archived), sort order.
- Listing: debounced name/slug search, status and root filters, per-page pagination, responsive table and mobile cards.
- Security: server checks on both page and API; session expiration/419/403 failures shown; category delete confirmed and 204 handled; no unapproved direct SQL or new API permissions.
- Existing category/parent publishing rules remain in P3B.1 backend. Editor excludes its own ID and visible descendants from parent dropdown; backend is authoritative if 200-option cap prevents complete hierarchy discovery.
- UI preserves reserved/soft-deleted slugs and reports server validation messages; it does not introduce redirects or scheduled publication.
- Does not change the existing destination backend, customer dashboard, Fortify, audit logging, admin home, or page builder.

## Browser checklist

1. Log in as Super Administrator or a content editor with `taxonomy.manage` and open `/admin/tour-categories`.
2. Create a draft root category with an ASCII URL-safe slug; verify new row and `taxonomy.category.created` audit event.
3. Create a draft child, select a parent, edit its description and order, and verify update audit event.
4. Publish parent before child; attempt to publish child under draft parent and verify field-level error.
5. Search, switch status/root filters, page forward/back, and check mobile/tablet overflow.
6. Attempt to reparent a parent under its child; verify rejection, no cycle, no audit mutation.
7. Attempt delete with live children (button disabled; backend also rejects); delete a leaf and verify it disappears while slug remains reserved.
8. Log in as an ordinary customer or review moderator: expect 403; suspend editor: page/API immediately deny.
9. Confirm `/admin/destinations` backend routes, `/admin/staff-users`, `/admin/roles`, `/admin/audit-logs`, login, dashboard, and public home remain functional.
10. After changes run all quality gates, including `php artisan test`, PHPStan, TS types and Vite build.

## Documented limitations

- Parent selector API currently returns at most 200 non-archived categories; for larger catalogs, implement server-side parent search in P3B.5.
- Categories currently have no standalone public detail route. Page-builder integration and media/SEO management are future milestones.
- Admin navigation/dashboard link integration is intentionally reserved for P3B.5. Use the direct page URL during P3B.3.
- P3B.1 server categorization and audit transactions are authoritative. The browser is an editor, not a security boundary.
