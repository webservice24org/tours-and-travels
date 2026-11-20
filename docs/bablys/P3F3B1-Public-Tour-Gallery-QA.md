# Bablys P3F.3B.1 — Public Tour Gallery QA

## Contract

- Only existing files on the configured `public` disk, with public visibility and allowlisted JPEG/PNG/WebP/AVIF MIME+matching extension, are exposed.
- Exactly four image props are allowed: `src`, `alt`, `width`, `height`. No storage disk/path, uploader, booking, price, seat or internal inventory metadata.
- Featured first; then `sort_order`, then database ID. At most 12 images exposed; 160 rows scanned max (fail-closed limit).
- Tour access MUST go through `Tour::publiclyAvailable()` in `PublishedTourDetail`; gallery service is not an authorization component.
- Mobile-friendly button thumbnails change a single image hero; keyboard accessible, pressed state and count announced. Broken public URLs show a fallback.
- Image changes do not create a booking request, payment, seat hold or quote.
- Standalone `PublicSiteLayout` from P3F.3A remains the explicit page layout; NO Laravel dashboard/sidebar.

## Automated gates

Use only dedicated `bablys_suite_clean_test` with `APP_ENV=testing`, `BABLYS_REAL_MYSQL_RACE` unset and verified active DB.

```bat
php artisan test --filter=PublicTourGalleryTest --stop-on-failure
php artisan test --filter=PublicTourDetailFoundationTest
php artisan test --filter=PublicTourMediaCardsTest
php artisan test
```

In a **new normal development** CMD window:

```bat
vendor\bin\phpstan analyse
npm.cmd run types:check
npm.cmd run build
```

## Browser checks

1. At `/tours/{published-slug}`, upload 2+ safe public photos through the existing admin tour-media workflow. Confirm hero and thumbnails use them, featured photo appears first and clicking thumbnails changes hero.
2. Verify no Laravel starter sidebar, Dashboard, Documentation, Repository or admin navigation leaks into public pages.
3. Test mobile 320px/375px, tablet 768px, desktop 1440px. No horizontal scroll; images crop responsively; keyboard Tab/Enter/Space select thumbnails; pressed state visible.
4. No photos: friendly fallback. One photo: hero only. Broken public URL: fallback rather than broken image. 13+ images: at most 12 appear.
5. Ensure private disk image, SVG, malformed path, missing file, or draft/scheduled tour content is not present in Inertia JSON or HTML page source.
6. Enquire Now links to non-binding request form; browsing or selecting gallery photos cannot change bookings, allocations, payments or price.
7. If public photos do not load, check `storage:link`/public disk visibility and whether actual uploaded images live in the `public` disk. Do not move private images to public automatically.

## Deferred P3F.3B.2

Real day-by-day itinerary and departure previews require source-verified `TourItineraryDay` and `TourDeparture` model/migration fields and publication policy. Specifically inspect `database/migrations/2026_11_19_050001_create_travel_tour_itinerary_days_table.php` and `database/migrations/2026_11_20_070001_create_travel_tour_departures_table.php`, and their model classes. No guessed columns or internal seat inventory appear in this pack.
