# P3F.2B.2 — Public media cards and photo-first tour listing

## Design reference

The attached reference depicts a 3-column travel grid with large photos, green duration badge,
destination line, and a booking CTA / price footer. This package implements the same hierarchy in
Bablys' TSX page, with real admin media records and no unapproved transactional promises.

- 3:2 cover photograph on desktop and mobile; 1/2/3 responsive columns (mobile/tablet/desktop).
- Days badge uses the actual `duration_days`; nights are **not** invented.
- Destination is the first **published** linked destination; draft/archived is never shown.
- CTA `Enquire Now` opens the *already-existing* public `/tours/{slug}/booking-request` form.
- Pricing says `On enquiry` — no fixed, discounted or starting price claimed before public price policy.
- Cards use real tour title/summary, accessible alt, responsive fallback, keyboard focus and lazy images.

## Media security / backend contract

Provided schema: `travel_tour_media` with `tour_id`, `disk`, `path`, `mime_type`, dimensions,
`alt_text`, `sort_order`, `is_featured`; provided model: `App\\Models\\Travel\\TourMedia`.

`PublicTourCardMedia` accepts only page IDs that already passed `Tour::publiclyAvailable()`.
It uses a single bounded media DB query (up to 480 candidates per page), selects featured then
sort_order then id, checks disk=`public`, configured disk visibility=`public`, image MIME and
extension allowlist, normalized relative path without traversals, positive dimensions/size,
filesystem existence and safe generated HTTP(S) or single-slash relative URL. SVG / private disk / raw
storage paths are not exposed. Disk/storage errors produce a card fallback, not an HTTP 500.
The cover payload is strictly `{src,alt,width,height}` or null. This is not a substitute for the
existing upload validation. Never expose the `local` or any private disk via a public route.

**Deployment prerequisite:** Confirm `config('filesystems.disks.public.visibility') === 'public'`;
verify images are uploaded to the Laravel public disk, with a valid `path`, and run
`php artisan storage:link` if public local storage URLs are not resolving. Test this only on
your local/staging environment; do not overwrite an existing symlink.

## Local QA / acceptance

1. Run installer with `-WhatIf`, review exactly 2 REPLACE + 3 ADD, then install; files are SHA-checked.
2. Check the actual test DB is `testing | bablys_suite_clean_test`, race opt-in disabled.
3. `php artisan test --filter=PublicTourMediaCardsTest`
4. `php artisan test --filter=PublicTourSqlPaginationTest` (use user's corrected privacy assertion),
   `php artisan test --filter=PublicTourBrowseEnhancementTest`, `php artisan test`
5. Regular dev terminal: `vendor\\bin\\phpstan analyse`, `npm.cmd run types:check`,
   `npm.cmd run build`.
6. Browser at `/tours`: test desktop three columns, tablet two columns, narrow mobile one column;
   verify images/fallback, photo aspect ratio, no horizontal scrolling, focus states, filter retention,
   image alt, destination label, and functional `Enquire Now` non-binding form.
7. Confirm no false prices/night counts/seat claims. Verify draft/scheduled/private images are absent.

**No changes** to routes, migrations, TourMedia model, checkout, allocations, requests or payment logic.
Media/HTML browser QA and actual Laravel runtime checks require the user's Windows environment.
