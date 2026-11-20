# Bablys P3F.3C — Tour Detail Integration & UX Hardening

## Scope

- Published-only tour detail remains GET `/tours/{slug}` and independent of the admin dashboard layout.
- Public page uses sidebar-free `PublicSiteLayout`; public `/tours` and tour detail remain linked.
- Visible and conditionally rendered on-page navigation points to Overview, Photos (only if multi-image), Itinerary (when present), Departures (when present), and Enquire.
- Gallery hero has previous/next controls, keyboard-focusable thumbnails, ArrowLeft/ArrowRight/Home/End handlers, selected state announcements and fallback for broken images.
- Mobile has a readily available enquiry link after the overview without a fixed overlay; desktop retains the existing enquiry panel.
- Canonical URL uses the published-tour route without advertising query strings; title/description and safe public Open Graph details are generated without pricing or availability claims.

## Browser manual acceptance — mandatory

1. Open `/tours` and a published `/tours/{slug}` at mobile ~360px, tablet ~768px and desktop ~1440px. Verify no horizontal scroll, no `Laravel` dashboard sidebar, no overlapping content.
2. Tab from the public `Skip to content` link. Follow on-page anchor links. Conditional sections must not create dead anchors when absent.
3. Populate a public tour with 2+ images. Tab between thumbnails and use ArrowLeft, ArrowRight, Home, End. Confirm focus ring and hero image/selection/counter update. Test prev/next with pointer and keyboard Enter/Space.
4. Remove one public image file while its DB row remains. Refresh. Check fallback to an available photo; when all fail, check labeled no-photo placeholder. Check thumbnails become disabled only for failed images.
5. Navigate between two tour slugs via the website and confirm gallery selection and error states do not leak from the previous tour.
6. Verify published tour with no gallery, itinerary or open departures displays a usable page, no empty section anchor, and the nonbinding enquiry CTA.
7. Check mobile enquiry CTA and desktop enquiry panel both open the existing `/tours/{slug}/booking-request` form, not checkout or payment. Check 404 for draft/scheduled/archived tour.
8. Inspect `<head>`: canonical URL must be the correct published tour URL, with no tracking parameters; OG image only if public image URL is absolute. In production set `APP_URL` to the canonical Bablys website HTTPS origin.
9. Inspect Inertia response payload for no seat totals, booking IDs, staff meeting points, deadlines, internal notes, private paths or invented prices.
10. Smoke-test back/forward navigation, text zoom 200%, screen-reader landmarks and reduced motion. Ensure no JS errors in browser console.

## Local QA (never on development database)

On dedicated `bablys_suite_clean_test`, `APP_ENV=testing`, `BABLYS_REAL_MYSQL_RACE` cleared:

```bat
php artisan test --filter=PublicTourDetailExperienceTest --stop-on-failure
php artisan test --filter=PublicTourItineraryDepartureTest
php artisan test --filter=PublicTourGalleryTest
php artisan test --filter=PublicTourDetailFoundationTest
php artisan test
```

In normal application terminal:

```bat
vendor\bin\phpstan analyse
npm.cmd run types:check
npm.cmd run build
```

No migrations, price changes, seat changes, auth changes, routes or booking changes in this ZIP. Do not consider P3F.3C closed until local tests, build and browser acceptance pass.
