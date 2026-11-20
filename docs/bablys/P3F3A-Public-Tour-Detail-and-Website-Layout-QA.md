# Bablys P3F.3A — Public Tour Detail + Sidebar-free Website Shell

## Scope

- Public `GET /tours/{slug}` uses the exact same `Tour::publiclyAvailable()` publication policy as P3F.2.
- Header and footer use a **public** layout, not Laravel AppLayout/AppSidebar; updated `/tours` page and new detail attach it as explicit Inertia `layout`.
- No fabricated price, departure date, nights, capacity, seat status or instant checkout. Existing enquiry CTA remains nonbinding.
- Read-only hero image reuses the P3F.2B.2 public-disk-only `PublicTourCardMedia` security filters.
- Published category/destination names only; indexed/scheduled/draft/archived tours excluded.
- No DB schema change, no existing booking code modifications, no new JS dependencies.

## Mandatory browser manual checks (Chrome/Firefox/mobile)

1. In an ordinary browser visit `/tours` and `/tours/{published-slug}`. **The Laravel brand/sidebar, Dashboard, Repository, Documentation, user account name and admin links must NOT be visible**. Instead Bably's Club site header + footer should be present.
2. Go to `/` (existing public home), `/tours`, detail, `/tours/{slug}/booking-request`, `/booking-requests/manage` while logged out and logged in. **If any public route still inherits sidebar**, inspect `resources/js/app.tsx` Inertia `resolve` function / default layout injection. If it unconditionally wraps every page in AppLayout, edit that resolver to apply the admin shell only for admin/account/dashboard pages; don't blindly overwrite this unknown entrypoint. The P3F.3A pack explicitly assigns public page layout on `/tours` + detail but cannot override a wrapper forcibly applied above Inertia by custom local code. Share `resources/js/app.tsx` before further root-layout modifications.
3. Tour detail has one `h1`, breadcrumb navigation, category/destination chips, responsive hero, readable text, no clipped content or horizontal overflow at 320–1440px, and tab focus/keyboard navigation visible.
4. Draft/scheduled/archived tour slug and unknown slug must return 404, not reveal titles/photos in response sources.
5. Existing `/tours/{slug}/booking-request` must still work and remain non-binding; no seats, payment, or confirmed booking is allocated by simply viewing detail.
6. In phpMyAdmin/admin, upload a valid image to the `public` disk and verify detail hero; private/missing/invalid images must render fallback. Check `php artisan storage:link` only if symlink missing and your deployment uses public disk.
7. Existing homepage visuals/content from `https://bablys.club/` are migration design references, not blindly copied claims/data. Menu and footer will become admin editable in later builder stages.

## Automated

Use isolated `APP_ENV=testing` `bablys_suite_clean_test`, `BABLYS_REAL_MYSQL_RACE` unset. Verify actual DB connection first.

```bat
php artisan test --filter=PublicTourDetailFoundationTest
php artisan test --filter=PublicTourMediaCardsTest
php artisan test --filter=PublicTourSqlPaginationTest
php artisan test
```

Normal development terminal:

```bat
vendor\bin\phpstan analyse
npm.cmd run types:check
npm.cmd run build
php artisan route:list --path=tours
```

## Deferred to P3F.3B

Full itinerary day blocks, gallery and approved public departure/pricing DTOs require actual `TourItineraryDay`/pricing/departure models and visibility contracts. Do not guess database columns or expose nonbinding quotes as guaranteed prices.
