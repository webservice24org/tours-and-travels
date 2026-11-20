# Bablys P3C — Tour Packages Database & Domain Foundation

**Project:** Bablys Tours & Travels only (Laravel 13, Inertia React TSX)

## Scope and architectural boundaries

This package adds *exactly three* database tables:

1. `travel_tours` — the canonical package record: unique permanent slug; title, summary, rich-text-ready description; publication status, scheduling/published timestamp; tour duration; featured/order flags; nullable create/edit actor links; timestamps and soft delete.
2. `travel_tour_category` — many-to-many links to the **existing** `travel_tour_categories` table. Unique `(tour_id, category_id)`, a sort-order column, database FKs and indexes.
3. `travel_tour_destination` — many-to-many links to the **existing** `travel_destinations` table. Unique `(tour_id, destination_id)`, ordering and FKs.

The P2A contract named these three tables. The P3A/P3B taxonomy tables and their status/slug behavior are reused unchanged. A tour can be associated with multiple categories and destinations (no single hardcoded category field).

## Model and domain contracts

- `App\Models\Travel\Tour` includes typed relations (`categories`, `destinations`, `creator`, `lastEditor`), status enum casts, `publiclyAvailable()` and `ordered()` scopes.
- `TourSlug` shares the existing P3A canonical slug rules. A slug is unique across both active and soft-deleted tours and **must not change** until redirects exist.
- `TourTaxonomyRules` checks that selected category/destination IDs exist, are unique, not soft-deleted or archived, and each set has at most 30 IDs. Draft taxonomy is permitted for **draft** tour assignments; it does not make the tour publicly visible.
- `TourPublicationRules::assertReady` requires a nondeleted tour with a title, positive duration, a published category whose ancestor chain is published, and a published destination. The P3D write service must call this rule **inside its transaction** before publishing.
- `PublishedTourCatalog::tours()` is a bounded (1–50), deterministic **read-only future builder source**. It requires status `published`, `published_at <= now`, and currently published (non-deleted) category + destination associations. It filters by published category/destination slug and supports featured-only selection.

### Important publishing notes

- `scheduled` is **never** public on its own. Scheduled activation is a future scheduler task, not a P3C feature.
- `published_at` prevents accidental early exposure even if `status` is erroneously set to `published`.
- Price/departure/seat inventory is not stored or invented here. `travel_tour_price_options`, `travel_tour_departures`, and `travel_tour_itinerary_days` are intentionally reserved for **P3E**.
- A future P3D mutation must evaluate publish/read invariants and audit changes atomically; mere Eloquent `fill()` is not a secure admin API.
- Existing P3B taxonomy operations can archive/soft-delete taxonomy records. The public catalog automatically hides tours without an eligible association. P3D will add referential edit/delete safeguards for linked tours where required.
- The tour description is CMS content and must be sanitized at render time; P3C adds no renderer.

## Schema details

### `travel_tours`

`id`, `title`, `slug`, `summary?`, `description?`, `status`, `duration_days`, `duration_nights?`, `is_featured`, `sort_order`, `scheduled_for?`, `published_at?`, `created_by?`, `updated_by?`, `created_at`, `updated_at`, `deleted_at?`.

Money amounts are **not** modeled as floats. P3E will use ISO currency and integer minor units, as defined in P2A.

### Pivots

Both tables use a composite primary key, two FKs and `sort_order`. Hard deletion of a tour cascades assignments; hard deletion of an associated category/destination is restricted. Soft deletion of taxonomy preserves the links for historical integrity, and normal Eloquent queries exclude soft-deleted taxonomy. No destructive changes to P3A tables are required.

## Future modules and integrations

- **P3D:** Admin tour CRUD, Form Requests, role-based `tours.*` gates, transactional assignment sync, audit events, stable slugs, use unified Laravel `AppSidebar`, shared `ConfirmDialog`, `toast` notifications.
- **P3E:** Itinerary days, integer-money prices, departures, booking cutoff and availability; no fabricated price/seat counts.
- **P3F:** Public tour listings/details, SEO-canonical URLs, sanitization and publication rules.
- **Page Builder:** approved `PublishedTourCatalog` source with schema-limited JSON results. This package deliberately does **not** expose builder blocks/routes.
- **Later:** images/gallery, reviews, blog/SEO, CRM booking requests.

## Change impact

Additive PHP/models/migrations/tests and docs only. **No edits** to `routes`, admin TSX pages, `AppSidebar`, auth, RBAC, audit writers or current category/destination CRUD. No seeds or fabricated tour data.
