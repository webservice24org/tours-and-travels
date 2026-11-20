# Bablys P3B.7A — One Native Laravel Sidebar

## Why the duplicate menu appeared

The Laravel React starter kit's `AppLayout` already renders `AppSidebar` around authenticated pages. P3B.7 added an independent `<aside>` inside `BablysAdminLayout`, leading to two sidebars on `/admin/...` pages.

## Corrected architecture

- `resources/js/components/app-sidebar.tsx` remains the ONE desktop/mobile sidebar.
- `/admin/...` pages show `BablysNativeSidebarNav` inside its existing `SidebarContent`, using Laravel's existing `SidebarGroup` / `SidebarMenu` components.
- The native sidebar brand links to `/admin` and displays **Bablys CMS** on admin routes. The original Dashboard and footer items remain unchanged on non-admin routes.
- `BablysAdminLayout` becomes a **content-only wrapper**; it renders no `<aside>`, header, mobile drawer, or navigation fetch.
- `BablysNativeSidebarNav` reads the existing non-cacheable `/admin/internal/navigation` endpoint. Laravel middleware and policies continue to enforce access.
- All six existing Bablys admin pages stay unchanged.

## Security and state behavior

- Navigation is fetched only while displaying admin routes; the native sidebar renders the existing starter menu on customer dashboard routes.
- Navigation refetches when the Inertia pathname changes and cancels stale requests on cleanup. The server continues to validate every protected action.
- Owner-only links are filtered by the existing server service; they are not hardcoded into frontend authorization.

## Browser acceptance

1. Visit `/admin`, `/admin/staff-users`, `/admin/roles`, `/admin/audit-logs`, `/admin/tour-categories`, `/admin/destinations` as the Super Administrator. Expect **one sidebar** and a single top header.
2. Check the original sidebar is Bablys-branded on admin routes, with grouped CMS navigation and current-page highlight.
3. Shrink the browser to a mobile width. The **existing Laravel sidebar toggle** opens/closes the only drawer; the main content must not horizontally overflow.
4. Visit `/dashboard`. Ensure the normal starter Dashboard navigation and account menu remain available.
5. Test as taxonomy-authorized staff; taxonomy links appear but owner-only routes do not. Change permissions/suspend the account and verify authorization is enforced.
6. Confirm login, registration, account settings, staff CRUD, roles, audit, categories, and destinations still work.

## Installation safety

`install-p3b7a.ps1` performs a non-writing preflight, checks SHA-256 for new files, preserves custom `app-sidebar.tsx` edits except for strict-anchor replacements, and accepts only the original P3B.7 versions of the layout and shared-layout test. If it sees unexpected changes, it refuses to overwrite them. It creates backups in `storage/app/bablys-installer-backups/`.

No migrations, routes, PHP domain services, authorization logic or audit events change.
