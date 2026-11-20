# P3D.1 — Tour Management Backend Contract

**Project:** Bablys Tours & Travels (Laravel 13 + Inertia React TSX), independent of all other projects.

## Scope

This package adds the secure JSON CRUD backend on top of P3C `travel_tours`, `travel_tour_category`, and `travel_tour_destination`. It adds **no migrations**, Inertia views, sidebar entries, or public endpoints. P3D.2 adds the React TSX admin editor using the existing single Laravel `AppSidebar` and shared ConfirmDialog/toast components. P3E provides itinerary, real prices and departures; no fake amounts, seats, or dates are introduced here.

## Routes

| Method | Path | Required permission | Notes |
|---|---|---|---|
| GET | `/admin/internal/tours` | `tours.view` | Search, status, category, destination, featured, page size (1–50) |
| GET | `/admin/internal/tours/taxonomy-options` | `tours.view` | First 200 nonarchived categories and destinations, for first-stage UI |
| POST | `/admin/internal/tours` | `tours.create` | Domain additionally checks `tours.publish` for published/scheduled |
| GET | `/admin/internal/tours/{tour}` | `tours.view` | Full editing data |
| PUT | `/admin/internal/tours/{tour}` | `tours.edit` | Domain additionally checks `tours.publish` for live/scheduled mutations |
| DELETE | `/admin/internal/tours/{tour}` | `tours.edit` | Archive/unpublish live/scheduled first |

All routes also require authentication, CMS-staff access and rate limiting. The backend rechecks current permissions in the domain service (not only the controller). Existing role catalog already defines `tours.view`, `tours.create`, `tours.edit`, `tours.publish`—no grants are added.

## Mutation details

- **Drafts:** May exist without category/destination; don't appear publicly.
- **Published:** `tours.publish` is required in addition to edit/create privilege. The `TourPublicationRules` verify a complete published category ancestry and at least one published destination. `published_at` is assigned when entering published status.
- **Scheduled:** Requires `tours.publish` and a future `scheduled_for` timestamp, but remains private. The future scheduler is **not** installed by this package.
- **Existing published/scheduled edits:** Require `tours.publish` as well as `tours.edit` to avoid silent alterations of live tour information by a draft-only editor.
- **Slugs:** immutable after creation and never reused after soft delete; no redirects until SEO module.
- **Taxonomy:** zero to 30 unique categories and destinations, excludes archived/soft-deleted. Ordered pivot assignments saved transactionally. Empty arrays explicitly clear assignments on draft tours.
- **Delete:** soft-deletes only nonlive/non-scheduled tours; recorded as `tour.deleted`. Historical slug remains reserved.
- **Audit:** `tour.created`, `tour.updated`, `tour.taxonomy.changed`, `tour.deleted` events written inside the exact database transaction. Only curated field names, IDs and counts; not the full body, credentials, or rich text. No-op updates skip audit.
- **Safety:** No direct mass assignment of actor IDs, no client-supplied `published_at`, no calculated tour prices, no availability claims.

## UI handoff to P3D.2

Use the existing single Laravel `AppSidebar`, Bablys admin layout wrapper, shared ConfirmDialog, toast. Tour listing/search/filter/status badges, create/edit form and taxonomy multi-selects will use JSON endpoints above. Route-level permission checks are authoritative, not frontend hiding. If category/destination counts exceed 200, implement searchable options/pagination rather than silently truncating user selection. Continue to show no price/departure information until P3E.

## Production boundary

P2B operational production signoffs (least-privileged DB credentials, encrypted offsite backups/restore drill, archive key recovery, retention/legal holds) remain independent of local green tests.
