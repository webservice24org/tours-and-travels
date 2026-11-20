# Bablys P3B.2 — Destinations CRUD Backend Acceptance

## Scope

This Bablys-only change adds a **JSON-only** Destination CRUD API on the existing P3A `travel_destinations` schema. It reuses the Bablys `taxonomy.manage` Gate, enabled/verified staff middleware, transactional audit writer and `TaxonomySlug` normalization. No migrations, React screens, public routes, or changes to the category CRUD are included.

| Verb | URL | Behavior |
|---|---|---|
| GET | `/admin/internal/destinations` | Bounded search/filter/pagination |
| GET | `/admin/internal/destinations/options` | Up to 200 non-archived selection options |
| GET | `/admin/internal/destinations/{destination}` | Show active destination |
| POST | `/admin/internal/destinations` | Create destination; default draft |
| PUT | `/admin/internal/destinations/{destination}` | Partial update, publish or archive |
| DELETE | `/admin/internal/destinations/{destination}` | Soft delete only after unpublishing |

All endpoints require an authenticated, enabled, email-verified CMS member with a current `taxonomy.manage` permission. JSON guests receive 401; authenticated non-authorized users receive 403. Endpoints are throttled at 60/min. The mutation manager rechecks the Gate inside transactions.

## Data contract

- **Required on create:** `name`, `slug`, `country_code`.
- **Optional:** `region_name`, `city_name`, `summary`, `description`, `latitude`, `longitude`, `status`, `sort_order`.
- `country_code` is normalized to uppercase and validated as two ASCII letters; existence in ISO country datasets is **not** asserted by this milestone.
- `latitude` [-90, 90] and `longitude` [-180, 180] are optional **as a pair**, at up to 7 decimal places. Either both are null or both are set. Partial coordinate updates are permitted when they leave a valid pair.
- Statuses: `draft`, `in_review`, `published`, `archived`. No `scheduled` until publication dates/scheduler are available.
- Slugs use the same canonical P3A normalizer, are unique even across soft-deleted records and cannot be edited until redirect history exists.
- Published destinations must be archived (or otherwise unpublished) before soft deletion.
- No public unpublished data is exposed: the P3A `PublishedTaxonomyCatalog` remains the public read contract.

### Example JSON body

```json
{
  "name": "Cox's Bazar",
  "slug": "coxs-bazar",
  "country_code": "BD",
  "region_name": "Chattogram",
  "city_name": "Cox's Bazar",
  "summary": "Sea beach destination",
  "latitude": 21.4272,
  "longitude": 92.0058,
  "status": "draft",
  "sort_order": 10
}
```

### Search query parameters

`search` (name/slug/region/city), `status` (including `all`), `country_code`, `has_coordinates` (boolean), `per_page` (1-50). Sorting is stable by `sort_order`, then `id`.

## Audit and transaction safety

Audit actions are `taxonomy.destination.created`, `.updated`, `.deleted` with subject type `destination`. Events capture the responsible actor and allowlisted status/country/changed-field data. Names, descriptions, full request bodies, and credentials are excluded from mutation audit metadata. Updates that change nothing do not create a new audit event. Audit inserts are inside the same DB transaction, so a failed audit write aborts the change.

The existing database-level audit immutability protection remains untouched. Existing route registrations, login, staff and roles screens are preserved.

## Local acceptance checklist

- [ ] Installer `-WhatIf` lists only seven new files plus one route include.
- [ ] Installer executes without overwriting modified Bablys files.
- [ ] `php artisan route:list --path=admin -v` shows the destination routes once.
- [ ] `php artisan test --filter=AdminDestinationCrudTest` passes on a **disposable test database**.
- [ ] P3A/P3B.1 suites still pass.
- [ ] `php artisan test`, PHPStan, TS types, and Vite build are green.
- [ ] Database audit readiness reports no issues.
- [ ] Published-only catalog returns no drafts, archives or soft-deleted destinations.
- [ ] Super Admin / authorized editor CRUD works; customers and suspended staff receive 403.
- [ ] Audit logs show creation, material edit, and deletion without exposing description content.

## Handoff

P3B.3 adds React TSX Category Management. P3B.4 adds React TSX Destination Management. P3B.5 polishes navigation and taxonomy UX. Tour relationships, image galleries, reviews and builder-powered frontend display arrive in later milestones. No production go-live claim is made; P2B operational sign-offs remain separate.
