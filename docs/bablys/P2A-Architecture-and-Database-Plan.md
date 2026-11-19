# Bablys Tours & Travels — P2A Architecture & Database Plan

**Project boundary:** Bablys Tours & Travels only (`C:\xampp\htdocs\bablys-travel`).  
**Baseline:** Laravel 13 + React TSX + Inertia; P1 homepage, auth, dashboard, and tests user-confirmed working.  
**Milestone:** P2A (contract and database *planning*, not schema installation).  
**Date:** 2026-11-18 · **Status:** architecture proposal / implementation scaffold prepared; owner sign-off pending.

## 1. Outcome and constraints

The existing static website becomes an admin-editable CMS, not a hardcoded React replica. Staff will create tour categories and tours, assign images and galleries, manage editorial pages and blogs, moderate real traveler reviews, and assemble public pages plus header/footer through versioned, allow-listed visual blocks. AI assists editors with SEO but never auto-publishes tour facts, reviews, or metadata.

### Fixed guardrails

1. Keep existing `/`, `/dashboard`, registration, login, and current P1 tests operational. Never apply a speculative replacement of the starter-kit router or dashboard.
2. Only published CMS snapshots and approved reviews are public. Draft and scheduled content must not leak in normal public queries; authorized previews are separate.
3. Tour prices, availability, departures, ratings and verified-traveler claims come from domain records—not builder text or AI output.
4. Builder data JSON is configuration only; no arbitrary PHP, SQL, JavaScript, executable HTML, user-supplied component imports, or unrestricted query expressions.
5. Dynamic blocks operate through a versioned registry, preapproved data sources, server-side authorization, validated filters, safe output encoding and published-only queries.
6. Data isolation: public browsing may be anonymous; `/admin` requires an authorized staff identity. Existing customer registration does not grant administration rights.
7. Delete behavior must consider published-page references, foreign keys, revisions, and audit history. Preserve old slugs via explicit redirects.
8. Separate published vs editable state with revisions, preview, permissions, structured audit events and deterministic cache invalidation.

## 2. Boundaries and directories

| Domain | Ownership | Planned storage prefix | Service responsibility |
|---|---|---|---|
| Identity & authorization | staff/customer roles, permissions, audit | `cms_*` + existing `users` | policies, roles, audit |
| Content & builders | pages, templates, menus, revisions | `cms_*` | draft/review/publish, registry, rendering |
| Travel | categories, destinations, tours, itineraries, departures, pricing | `travel_*` | eligibility, canonical pricing, schedules |
| Media & galleries | asset metadata, variants, albums, ordered items | `cms_media_*`, `cms_gallery_*` | storage, rights, visibility |
| Editorial blog | categories, posts, revisions | `cms_blog_*` | publishing and discoverability |
| Trust & reviews | reviews, reports, replies, moderation | `travel_review_*`, `travel_reviews` | verified-booking validation, aggregation |
| Inquiries & booking | booking requests, status events, private notes | `crm_*` | lifecycle, notifications, privacy |
| SEO & AI | metadata, redirects, proposals, usage | `seo_*` | metadata, schema validity, approval |

Recommended PHP organization: `app/Domain/{Cms,Travel,Media,Reviews,Booking,Seo}`, `app/Http/Controllers/{PublicSite,Admin}`, form requests in `app/Http/Requests`, policies in `app/Policies`, app page DTO/transformers in `app/Http/Resources` or typed domain data. React public pages remain `resources/js/pages/public/` and admin pages are isolated under `resources/js/pages/admin/`. Prefer TSX for all React UI.

The content entity owns truth; the builder only decides *where and how* approved data appears.

## 3. Proposed database catalog

The machine-readable inventory is `P2A-Schema-Catalog.csv`. All proposed tables use Laravel `bigint` identity IDs unless a reviewed reason justifies UUID/ULID. `created_at` and `updated_at` default on domain tables. PII is never stored in JSON layout props. Exact column types/index names are confirmed when each phase's migrations are authored.

### Core CMS and access

- `users`: preserve starter schema and credentials; do not drop or replace existing users.
- `cms_roles`, `cms_permissions`, `cms_role_user`, `cms_permission_role`: proposed internal role-permission approach; **choose and freeze either native tables or an established ACL package in P2B, not both**.
- `cms_audit_events`: append-only actor/action/object/event/metadata with sensitive fields redacted.
- `cms_settings`: namespaced key/value, datatype, and permission scope; secrets remain in secured config/secret storage.
- `cms_menus`, `cms_menu_items`: navigational structure and ordered parent-child items, typed targets, availability.
- `cms_pages`, `cms_page_revisions`: canonical slug/route, status, published revision pointer, ordered versioned builder snapshot.
- `cms_templates`, `cms_template_revisions`, `cms_template_assignments`: header/footer/listing/detail/page variants, immutable versions and explicit conditional assignments.

### Tour catalog

- `travel_tour_categories`: hierarchical category tree; unique slug within defined route namespace, activation and sort.
- `travel_destinations`: geo/region metadata, hero asset and SEO relationship.
- `travel_tours`: canonical tour record, title/slug, summary/body, status, published timestamp, duration and policies.
- `travel_tour_category`, `travel_tour_destination`: many-to-many assignments with composite uniqueness.
- `travel_tour_itinerary_days`: ordered day/activity data, no duplication in builder.
- `travel_tour_departures`: local display time, timezone ID, UTC departure time, booking cutoff, status; avoid fabricated availability.
- `travel_tour_price_options`: priced options in ISO 4217 currency and integer minor units; avoid floats and arbitrary builder price overrides.

### Media and galleries

- `cms_media_assets`: original storage reference, MIME, dimensions, rights/credit, alt/caption, ownership, visibility, checksum and lifecycle.
- `cms_media_variants`: derived/responsive image references, dimensions and processing statuses.
- `cms_gallery_albums`: title/slug/status/cover/SEO, optional tour or destination association.
- `cms_gallery_items`: ordered album-to-asset links with optional per-use caption/alt override; unique `(album_id, media_asset_id)` unless explicit duplicates are approved.
- `cms_media_usages`: audited links from media to content; polymorphic pointers require application-enforced type allowlist and reference checks.

### Blog, reviews, inquiries and SEO

- `cms_blog_categories`, `cms_blog_posts`, `cms_blog_post_category`, `cms_blog_post_revisions` for editorial lifecycle and builder templates.
- `travel_reviews`: tour_id nullable for company-wide feedback; reviewer identity/consent; 1–5 score; moderation status; optional verified booking linkage. Never assume all guests are verified.
- `travel_review_reports`, `travel_review_replies`, `travel_review_events`: abuse and moderation separated from public review display; all actions auditable.
- `crm_booking_requests`, `crm_booking_events`, `crm_booking_notes`: inquiry-first workflow, private contact data, scoped staff permissions.
- `seo_metadata`, `seo_redirects`, `seo_ai_proposals`, `seo_ai_usage_logs`: canonical, robots, social tags, redirects, reviewable AI drafts, privacy-safe usage and token/cost controls.

### Integrity principles

- Unique slugs and reserved-word blocking. A published URL rename is transactional with a tested 301 redirect decision.
- Foreign keys for known parents; indexed status/date/category relations, unique pivot combinations. Consider optional foreign keys as `nullable()->constrained()->nullOnDelete()` only where valid.
- Approval/publish operations must recheck permissions and referenced content *inside the same transaction*; scheduled jobs must revalidate.
- Published builder revisions are immutable. Editing creates a draft revision, not in-place edits to a public snapshot.
- Approved review aggregate caches are invalidated on moderation changes; totals must be computed only from eligible reviews.
- Physical media deletion must be blocked while used by published content unless the system first replaces/reassigns references safely.

## 4. Migration rollout (defer execution until owning phase)

| Stage | Owner | Initial migration groups | Gate |
|---|---|---|---|
| P2B–P3 | Core & publishing | role/permission, audit, settings, menus, page/template version structures | authorization and draft/public isolation |
| P4 | Taxonomy | categories, destinations | unique slugs, tree integrity |
| P5 | Media | assets, variants, galleries, ordered items/usages | rights, access, ordering |
| P6 | Tours | tours, pivots, itineraries, departures, price options | integer money, timetable, published-only |
| P7–P8 | Builder | revisions, block snapshots, template assignments, layout revisions | whitelisted blocks, preview, rollback |
| P10 | Blog | posts/categories/revisions | permission, schedule and previews |
| P11 | Reviews | reviews/reports/replies/events | approval, verified-badge correctness |
| P12 | Booking CRM | requests, status events, notes | PII safety and transitions |
| P13–P14 | SEO + AI | metadata, redirects, AI proposals/usage | human approval, provenance |

**P2A package deliberately creates no database tables, new pages, routes, or auth permissions.** It provides architecture contracts and tests that can be installed without disturbing P1.

## 5. Publication and revision contract

- `draft` → optional `in_review` → `scheduled` or `published` → `archived` as policy allows. Rejected editorial submissions return to draft with a reason/event; no need for a separate public `rejected` status.
- Only `published` content returns from ordinary public repositories. Scheduled records are published by an authorized scheduler/job after timezone/availability validation.
- Each page/template/blog edition is a distinct immutable revision with `schema_version`, editor, change note, approval data and publication timestamps.
- Use expiring signed, staff-authorized preview access, noindex response header, no accidental public caching. Signing alone does not override staff permissions.
- Allow explicit rollback to a prior snapshot as a **new** published revision with audit logging rather than rewriting history.
- Publish/rollback raises domain events and invalidates affected public cache keys, SEO outputs and builder queries.

## 6. Route, API and DTO policy

See `P2A-Routes-and-Builder-Contracts.md` for route patterns, allowed block sources and the versioned JSON shape. Short version: guest pages and staff routes use separate controllers, policies, validation and serialization. Never expose raw unpublished Eloquent relations in Inertia shared props. The public homepage remains unchanged until P2C/P2D define and test the transition to CMS-driven rendering.

## 7. Permission model

`P2A-Permission-Matrix.csv` proposes permission keys such as `tours.create`, `tours.publish`, `gallery.publish`, `reviews.moderate`, `builders.publish`, `seo.ai.review`, `bookings.view`. All staff actions require explicit authorization checks. Admin privileges must not be derived from a normal registered user. AI suggestions must require `seo.ai.review` and separate publish permission.

Roles (initial, not hardcoded global privileges): `super_admin`, `administrator`, `designer`, `content_editor`, `seo_editor`, `review_moderator`, `booking_agent`; customer/visitor is **not** a staff role. Grant least privilege. Any chosen package and permission seed set must be idempotent.

## 8. Security, performance and privacy

- Server-side HTML sanitization/encoding; strict allowed React components; never permit arbitrary script injection from builder JSON.
- Version document validation before saving and again before publishing; reject unknown block types, invalid filters, hidden/unpublished target IDs, or invalid rich-text formats.
- Uploads: MIME/content validation, limits, AV scanning hook, safe derivative workers, EXIF/privacy removal, private originals as appropriate.
- Reviews: moderation + rate limits + consent; verification only with an actual validated booking relation; public data minimized.
- SEO/AI: no external API keys in DB plaintext or TSX props; redact prompts/logs containing customer personal data.
- Page rendering: indexed read models, eager-loading, cache-key versioning, no N+1 explosion; count/rating from approved reviews only.

## 9. Owner decisions and safe defaults

| Decision | P2A safe proposal | Confirm before implementation |
|---|---|---|
| Visual builder power | controlled section presets and responsive properties; no free-form scripts | before P7 |
| Publishing privileges | separate edit vs approve vs publish; super admin emergency path audited | before P2B |
| Review submissions | moderated, optional booking verification, anonymous display names redacted | before P11 |
| Gallery rights | only owner-approved/licensed photos, credits, EXIF stripping | before P5 |
| Booking launch | inquiry/quotation first; payment separately scoped | before P12 |
| SEO AI | draft suggestions + human approval, no fabricated travel claims | before P14 |
| Languages/currencies | English-first structure with future localization; ISO currency per price | before P6 |

## 10. P2A closure gate

- [ ] Owner approves domain/table naming, key relationships, route reservations and staff/public boundaries.
- [ ] Schema catalog and permission matrix reviewed for missing business workflows.
- [ ] Contracts installed without overwriting P1, and focused PHP tests pass.
- [ ] Full suite, TS type check, production build and baseline home/auth routes remain green.
- [ ] P2B implementation choice for roles/permissions is recorded.

**P2A progress:** design + scaffold prepared. **Not complete** until installed, verified, and approved on the local Bablys project.
