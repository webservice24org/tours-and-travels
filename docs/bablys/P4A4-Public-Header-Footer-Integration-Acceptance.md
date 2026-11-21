# P4A.4 — Public Header/Footer Integration & Security QA

## Scope / architecture

This phase connects the **published** P4A.1 site settings and P4A.2 navigation menus to the existing, sidebar-free `PublicSiteLayout`. The P4A.3 publishing manager remains authoritative. The public page requests `/api/public/site-chrome` with `cache: no-store` and renders React text nodes, never editor HTML. The endpoint serves only the two existing domain `publicSnapshot()` methods.

No migration is added. No booking/seat/price/payment controller, domain service, or database table is modified. Admin sidebar remains unchanged.

### Menu key conventions

- `main-header`: public desktop + mobile primary navigation.
- `footer-links`: public footer menu (preferred).
- `footer`: fallback alternate footer key.
- If `main-header` was **not published**, the original Home / Explore Tours links remain. An intentionally published **empty** menu yields no editorial links; customer-account link and Find a Tour CTA remain.
- Root, child, grandchild entries render using an accessible summary/disclosure structure. Parents retain their own safe link inside the expanded group.
- External links must be HTTPS, without credentials. New-tab links use `rel="noopener noreferrer"`.

### Browser acceptance checklist

1. Open `/admin/site-settings` and save a new draft brand/contact/social/legal value. Confirm that the public header/footer **does not change** before publication.
2. Create `main-header` and `footer-links` in `/admin/navigation-menus`; add safe internal links, nesting, order, and an external HTTPS link. Save drafts. Public pages must still show the last published navigation.
3. At `/admin/cms-publishing`, publish settings and both menus. Hard-reload `/tours` and a tour detail page. The desktop/mobile menus, brand name, tagline, contact and footer links should reflect the published values. Check browser Network tab: `/api/public/site-chrome` returns no-store JSON with **no draft/private fields**.
4. Test nested link navigation by keyboard (Tab, Enter/Space, Escape for mobile menu), touch and mouse. Confirm no horizontal scrolling at 320px and 200% zoom, and meaningful keyboard focus.
5. Publish a subsequent draft and confirm the public site changes on reload. Roll back to a prior published event and verify prior public values return. Confirm newer private drafts stay private.
6. Sign in as a customer: `/account/travel`, `/account/bookings`, and quote approval must still work. Log out: account link must lead to the existing login/verification path. Neither customer nor public pages may have an admin sidebar.
7. Test with **no** published settings/menus, and with the chrome API temporarily unavailable: the safe original Bablys brand and Home/Explore Tours fallback should remain usable.
8. Inspect footer legal/social external links for HTTPS and `rel="noopener noreferrer"`. Inspect the response for forbidden fields (`draft_payload`, editor IDs, booking references, payment status, private secrets).
9. Verify existing tour listing, details, SEO and booking/seat availability behaviors are unchanged. Run the full isolated MySQL test suite, PHPStan, TypeScript check and Vite build.

### Known limitation / next workstream

**The CMS chrome is loaded client-side after the initial HTML is rendered.** Therefore the initial server-rendered HTML may still show fallback branding/navigation until hydration/fetch. This phase does not promise crawler-visible SSR branding/menu content or replace the visual header/footer builders. Before production SEO launch, supply CMS chrome as server-rendered Inertia shared props or SSR data and verify a real crawler snapshot. Logo/favicon media upload and design variants belong to later Media/Builder phases.

### Safety prerequisites

- Use only `testing | mysql | bablys_suite_clean_test` (or another verified disposable non-race `_test` DB) for Laravel feature/full tests.
- Do not run `php artisan test` from the normal development environment.
- Browser checks are manual; PHPUnit source contracts do not certify WCAG or deployed crawler behavior.
