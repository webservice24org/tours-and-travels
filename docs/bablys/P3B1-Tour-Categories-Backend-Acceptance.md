# Bablys P3B.1 — Tour Categories CRUD Backend Acceptance

## Scope and interfaces

P3B.1 adds protected **JSON-only** category endpoints. It uses the existing `travel_tour_categories` table and model from P3A; the `taxonomy.manage` Gate and `EnsureCmsStaff` middleware from P2B; and the existing immutable audit recorder via `CmsMutationAudit`.

| Verb | URL | Behavior |
|---|---|---|
| GET | `/admin/internal/tour-categories` | Search, `status`, `root_only`, `parent_id`, bounded pagination |
| GET | `/admin/internal/tour-categories/options` | Lightweight non-archived selection options; maximum 200 |
| GET | `/admin/internal/tour-categories/{category}` | Single non-deleted category |
| POST | `/admin/internal/tour-categories` | Create category; defaults to draft |
| PUT | `/admin/internal/tour-categories/{category}` | Partial update and safe reparenting |
| DELETE | `/admin/internal/tour-categories/{category}` | Soft-delete only when no active children |

All routes require an authenticated, enabled, email-verified CMS staff member with the **live** `taxonomy.manage` Gate. The domain service authorizes again inside transactions. Routes are throttled to 60 requests/minute. Guests using JSON requests receive 401; unauthorized authenticated accounts receive 403.

## Editorial data / business guards

- Uses `name`, canonical ASCII `slug`, `parent_id`, `summary`, `description`, `status`, and `sort_order` from P3A.
- Allowed statuses for this API: `draft`, `in_review`, `published`, `archived`. `scheduled` is deliberately excluded until the scheduler and publish timestamps exist.
- Canonical slugs are normalized on create and reserved even after soft deletion. **Existing slugs cannot change** until permanent redirect history is available in a later milestone.
- Category cycles, deleted parents and archived direct parents are rejected. Publishing a category requires all ancestor categories to be published.
- A published parent cannot be unpublished while it has directly published children. Parents with active children cannot be deleted.
- Mutations use a conservative tree-wide `FOR UPDATE` lock in an existing transaction to reduce concurrent reparenting races. This locking policy should be load-reviewed when the tree grows substantially.
- No-op edits do not write an audit event; content bodies and full request payloads are never sent to the audit recorder.
- Audit event keys: `taxonomy.category.created`, `taxonomy.category.updated`, `taxonomy.category.deleted`; subject type: `tour_category`.
- A failed audit write must roll back the category mutation.

## API request example

```json
{
  "name": "Family Tours",
  "slug": "family-tours",
  "parent_id": null,
  "summary": "Curated family journeys",
  "status": "draft",
  "sort_order": 10
}
```

Never trust client-calculated or client-asserted permission data. Content fields are validated by Form Requests; Eloquent models are bound on server and are soft-delete aware.

## Acceptance checks

- [ ] Install preview (`-WhatIf`) shows only seven new files and a route include.
- [ ] Installer succeeds without clobbering prior customizations.
- [ ] Category routes appear once in `php artisan route:list --path=admin -v`.
- [ ] `AdminTourCategoryCrudTest` passes in a **separate disposable testing database**.
- [ ] Full tests, PHPStan, TypeScript checks, frontend build remain green.
- [ ] Authorized staff can create/read/update/soft-delete categories through JSON endpoints; unprivileged users cannot.
- [ ] Events appear in `/admin/audit-logs` for material changes; no-op updates produce no event.
- [ ] Public homepage, auth, existing admin pages still work.

## Scope boundaries / handoff

P3B.1 does not introduce React forms, sidebar links, destinations CRUD, extra migrations, tour relationships, or new public routes. P3B.2 adds destination CRUD. P3B.3 creates the category React TSX UI, P3B.4 the destination UI, and P3B.5 navigation/hierarchy UX hardening.

**Operational note:** Local tests do not grant production release approval. The P2B operational security signoffs remain separate.
