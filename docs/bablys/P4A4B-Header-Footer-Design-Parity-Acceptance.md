# P4A.4B — Public Header/Footer Design Parity Acceptance

Owner reference: current https://bablys.club/ and provided 1920px screenshots (21 Nov 2026).

## Visual scope
- Navy `#03143a` utility strip: phone, email, office, Follow Us.
- White navigation bar: Bablys logo, published `main-header` nested menu, orange `Book Now` CTA routing to existing `/tours` (request-only; **not** a seat reservation).
- Navy footer: brand/about/social/contact, Domestic Tours, Global Destinations, Head Office; copyright/legal and footer bottom links.
- Responsive desktop/tablet/mobile menu, focus/keyboard Escape, skip link, focus-visible rings. No admin sidebar.

## CMS mapping
- Published branding, contact, social and policy links come from P4A.1/P4A.3.
- Header: `main-header`; footer bottom row: `footer-links`.
- Optional two footer column menus: **`footer-domestic`**, **`footer-global`**. Create and publish these with actual tour/category destinations to override reference fallback links.
- If optional column menus are unpublished, published children of the Domestic/Global header groups are reused. Otherwise reference labels fall back to the existing `/tours` listing. Do not treat fallback labels as linked category pages.
- Reference contact values (phone/email/office) display only when **all three CMS contact fields are blank**. If any is published, only published fields are shown. Verify fallback contact is still accurate before launch.
- The screenshot-sourced logo asset `/images/bablys-club-reference-logo.png` is **low resolution**. Replace with owner-supplied original brand asset when Media Library supports logo selection. For a renamed non-Bablys site, the component shows the CMS brand text rather than the Bablys logo.
- The P4A.1 social schema supports Facebook, Instagram, YouTube, LinkedIn. TikTok and refund-policy fields are not part of this patch and must not be fabricated.

## Release sign-off
1. Open `/tours`, an individual tour, the booking-request form, and `/account/travel`. Confirm same public chrome everywhere, no extra sidebar.
2. At 1920px compare with screenshot: utility strip, logo size/position, menu spacing, orange CTA, four-column footer, navy background.
3. At 320/375/768/1024px verify no horizontal overflow, tappable menus, focus ring, Escape closing with trigger focus restored.
4. Publish `main-header`, `footer-links`, and optional `footer-domestic` + `footer-global`; confirm only published values render; drafts stay private; rollback restores old links.
5. Check no false 'booking confirmed' messages or seat-count resets; Book Now goes to `/tours` for selection.
6. Test external links always use `noopener noreferrer`, validate that no drafts or customer private data are present in the endpoint.
7. Confirm site's chosen route targets are live before launch; temporary `/#about`, `/#contact`, `/#gallery`, `/#reviews` fallbacks may require CMS replacement.
8. Acknowledge the P4A.4 limitation: public chrome is fetched client-side after first render; SSR/crawler-visible final markup is a separate task.

No controller, route, PHP service, migration, booking, price, customer or seat-allocation changes.
