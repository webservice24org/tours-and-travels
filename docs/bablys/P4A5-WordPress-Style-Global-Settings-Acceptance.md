# P4A.5 — WordPress-style Global Settings workspace

## Scope

This pack adds **Global Settings → Overview / Header / Footer** links to the *existing* permission-filtered admin sidebar. It adds a simple two-step CMS authoring workspace, leaving the original P4A.1 settings editor, P4A.2 advanced navigation editor, P4A.3 publishing and rollback center, and P4A.4B public frontend **unchanged**.

This is WordPress-like usability, not a third-party WordPress plugin or unrestricted page builder. It uses the existing Laravel settings and menu records.

## Safe publishing workflow

1. Open `/admin/global-settings/header`, edit branding/top-strip values and click **Save draft**.
2. Click **Publish saved settings** after reviewing. This publishes the **whole site settings snapshot** because header/footer share one settings record. Footer fields from the current draft are preserved.
3. Click **Create Main Header menu** if needed; add links, use **Add submenu**, **Up**, **Down**, edit URLs, then **Save menu draft** and **Publish menu**.
4. For the footer, repeat for `footer-domestic`, `footer-global`, and `footer-links` using `/admin/global-settings/footer`.
5. Open **Preview / restore** for any history/rollback; it remains controlled by P4A.3.
6. Check the public site after refreshing. The P4A.4B site chrome loads published data client-side.

**Caveats:** Existing P4A.1 settings share one global draft/version; opening Header and Footer simultaneously can make one editor stale. Reload the stale tab, review values, and re-save. External links must be valid HTTPS, and internal paths must begin with `/`. Nothing is live until explicitly published. The header Book Now CTA and footer layout remain defined in the P4A.4B TSX template; this phase edits content and menus only. Logo/favicon upload and visual drag/drop header/footer builders are separate future work.

## Acceptance checklist

- [ ] Native admin sidebar displays **Global settings** group with Header and Footer as children; no second sidebar.
- [ ] `settings.manage` users see sidebar links; ordinary staff without permission cannot see or visit workspaces.
- [ ] Header editor saves draft and does not update `/api/public/site-settings` until explicitly published.
- [ ] Header menu can be created, edited, nested, reordered and published; published-only API contains only approved links.
- [ ] Footer editor exposes contact, socials, legal, and three footer menu slots.
- [ ] An invalid `javascript:` URL is rejected; spoofing a menu in another section returns 404.
- [ ] A stale draft/publish attempt is rejected; event history is recorded and rollback remains available.
- [ ] Desktop and mobile header/footer remain close to the approved bablys.club design; no public AppSidebar.
- [ ] Booking and customer/account flows are unaffected.
- [ ] PHPStan, TypeScript, production build, dedicated test DB focused/full suite are green.
- [ ] Public crawler-visible SSR for CMS header/footer is **not claimed**: P4A.4B remains client fetched.
