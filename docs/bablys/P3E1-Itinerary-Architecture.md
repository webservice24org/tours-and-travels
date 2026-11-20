# P3E.1 — Bablys Tour Itinerary Architecture

**Only for Bablys Tours & Travels** (Laravel 13 + Inertia React TSX). Prerequisites: P3C, P3D.1–P3D.4 installed, migrations/tests green, roles and CMS audit active.

## Canonical schema

- `travel_tour_itinerary_days` belongs to `travel_tours` via a cascading foreign key; a tour's `(tour_id, day_number)` pair is unique.
- `day_number` is the authoritative 1-based display order. The replacement API accepts an ordered list; it assigns day numbers 1…N.
- Each day has `title`, optional `location_label`, optional plain-text `description`, optional `overnight_location`, JSON `meals` (breakfast/lunch/dinner), JSON `highlights` (up to ten short text items), and timestamps.
- No departure dates, calendar days, prices, booking seats, room rates, transport inventory, GPS, media IDs, or meal prices are inferred from itinerary content.
- The day model provides the canonical child-to-parent `tour()` relationship. P3E.1 does **not** patch the existing `Tour` model; the parent reads days via `TourItineraryManager::forStaff()` until the P3E.2 UI integration adds any needed inverse relation.

## Domain service contract

```php
$manager = app(\App\Domain\Travel\Itineraries\TourItineraryManager::class);
$manager->replace($actor, $tour, [
    [
        'title' => 'Arrival and check-in',
        'location_label' => 'Dhaka',
        'description' => 'Airport pickup and hotel transfer.',
        'meals' => ['dinner'],
        'highlights' => ['Welcome briefing'],
        'overnight_location' => 'Dhaka',
    ],
    [
        'title' => 'City exploration',
        'meals' => ['breakfast', 'lunch'],
        'highlights' => [],
    ],
]);
$days = $manager->forStaff($actor, $tour);
```

Both calls are **domain service calls**, not externally exposed endpoints. Do not call from unauthenticated page code.

- `replace()` rechecks `tours.edit` inside its transaction, and also `tours.publish` for published/scheduled tour changes. It obtains a parent row lock, revalidates the current `duration_days`, writes all days atomically, and records a single `tour.itinerary.changed` audit event if content changed.
- Audit metadata contains only the previous count, new count, and changed day numbers—not raw titles, descriptions, location names, meals or highlights. Audit insert failure aborts the write transaction.
- Identical replacements produce no database writes or new audit records; existing day IDs remain intact at unchanged day positions.
- Replacing with `[]` clears itinerary days. Partial 1..N drafts are allowed; P3E.1 does *not* retroactively change current published-tour eligibility rules.
- `forStaff()` requires `tours.view` and refuses to disclose soft-deleted tours through normal Eloquent queries.
- `normalize()` rejects unknown keys, HTML tags, malformed lists, unsupported or duplicate meal names, duplicate highlights, missing/overlong titles and descriptions, and itineraries longer than the tour's duration (1–365).
- Plain text fields must be HTML-escaped when rendered by clients. Existing MicroWeb Tiptap continues to serve the **main tour description**; itinerary rich-text/media embedding requires a separate security and media-reference design. Don't pass day descriptions to `dangerouslySetInnerHTML`.

## Boundaries and follow-up

1. **P3E.2:** Add a dedicated itinerary section to the existing full-page Tour Editor, using the existing Laravel `AppSidebar`, admin layout, `ConfirmDialog`, and toast system. Build staff-only HTTP requests with FormRequest validation; route mutations only to `TourItineraryManager`, never direct unsupervised Eloquent updates.
2. **Tour-duration updates:** P3E.1 validates duration during itinerary replacement but intentionally does not patch P3D's existing `TourManager` duration-update path. P3E.2 must enforce that reducing `duration_days` cannot leave existing itinerary days beyond the new duration, and add a focused regression test. Until that integration, avoid shortening the tour duration below its saved itinerary count.
3. **Publication readiness:** P3E.5 will decide whether and when a complete itinerary is required for publish/booking readiness, preserving existing published tours during migration. Current published catalog visibility is not changed.
4. **P3E.3/P3E.4:** Price variants, currencies, departures and booking capacity are separate domain migrations and workflows. Do not fabricate price or capacity fields in itinerary content.
5. **Public output:** No public itinerary endpoint is included. A future public reader must require the parent tour to be `publiclyAvailable()` and sanitize/escape rendered text. This staff-only service must not be exposed to guests.
6. **Data recovery:** Normal tour soft-deletion leaves day rows intact for restoration; permanent deletion cascades to itinerary days. Back up MySQL before migration.
