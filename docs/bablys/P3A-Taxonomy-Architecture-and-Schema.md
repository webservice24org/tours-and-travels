# P3A — Dynamic Tour Categories & Destinations: database contract

**Project:** Bablys Tours & Travels only (`C:\xampp\htdocs\bablys-travel`).
**Foundation:** P1 + P2A/P2B locally accepted. This package is P3A only.

## Schema

- `travel_tour_categories`: `id`, nullable `parent_id` (restrict-on-delete FK), `name`, unique `slug`, summary, description, `status`, `sort_order`, timestamps, soft delete. Bounded category-tree guard prevents cycles, deleted parents, archived immediate parents, and deletion of parents with active children.
- `travel_destinations`: `id`, `name`, unique `slug`, required ISO 3166-1 alpha-2 `country_code`, optional region/city, summary, description, optional decimal latitude/longitude, `status`, `sort_order`, timestamps, soft delete.
- Initial status is `draft` for both. We reuse P2A `App\Domain\Cms\Enums\PublicationStatus`. A row is publicly selectable **only** when `status=published` and not soft deleted. P3B admin validation will constrain taxonomy statuses to `draft`, `published`, `archived` until explicit review/scheduling functionality is implemented.
- Uniqueness is global by slug **per table**, not per parent. Soft-deleted slugs remain reserved for redirect / canonical URL continuity. Category and destination slugs may coincide because they have different URL namespaces.
- No category-destination pivot: P3C adds `travel_tours`, `travel_tour_category`, and `travel_tour_destination` using the P2A contract.
- No media foreign keys until the media library is installed. `hero_media_asset_id` and SEO relationships belong to their owning phases; do not store image paths or SEO JSON directly in these taxonomy rows.

## Code ownership

- Models: `App\Models\Travel\TourCategory` and `App\Models\Travel\Destination`
- Domain validation: `App\Domain\Travel\Taxonomy\TaxonomySlug`, `CategoryHierarchyGuard`
- Safe public selector: `PublishedTaxonomyCatalog` returns bounded lists of public display fields, with no unpublished metadata or user-controlled SQL.

## Mandatory next-phase guards (P3B)

1. Admin routes require `auth`, verified email, active CMS staff, and `taxonomy.manage` authorization. No customer or guest taxonomy mutation.
2. FormRequests validate data types, slug rules/uniqueness, country code `[A-Z]{2}`, coordinate bounds (lat ±90, lon ±180), names/lengths, parent IDs, statuses and nonnegative sort order.
3. Invoke `CategoryHierarchyGuard::assertValidParent()` within a **transaction**, holding appropriate row locks during reparent operations to prevent concurrent cycle creation. The guard is not a substitute for write-side locking.
4. Invoke `assertCanSoftDelete()` on category deletion. Once tours are introduced, block or reassign referenced taxonomies and published builder references rather than silently removing them.
5. Treat published child categories below unpublished parents as a publishing validation error. In P3A the safe public source exposes *top-level* categories only.
6. When changing slugs of already published content, add a redirect-history mechanism before the feature is enabled. Otherwise, preserve slugs.
7. Record taxonomy create/update/delete/publish actions through the existing P2B audit domain in P3B. Avoid sensitive metadata and no-op events.
8. Do not introduce new permissions: `taxonomy.manage` exists in the P2A/P2B permission catalog. Confirm current staff role assignments before enabling the UI.

## Concurrency / migration notes

- MySQL uses a case-insensitive collation in many installations; the lower-case ASCII slug policy makes canonical storage consistent across MySQL and SQLite tests.
- MySQL can reject a hard delete of a category with children due to the foreign key. Soft deletion does not fire foreign key checks; use the domain guard in every administrative delete path.
- No migration in this package modifies `users`, existing CMS tables, authentication, headers, pages, or routes.
- This is not a production release: apply migrations after backing up the database, test on a disposable database, and audit the schema before enabling public taxonomy URLs.
