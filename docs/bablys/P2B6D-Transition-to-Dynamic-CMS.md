# P2B → Dynamic Bablys CMS Transition

**Scope boundary:** The completed foundation consists of authentication, protected administration, staff/RBAC, dynamic roles, and audit security. It **does not** mean tours, gallery, reviews, builders, blog, booking or AI SEO are implemented.

## Next development step: P3A — Tour Categories and Destinations domain

Plan the data model and validations for independently editable tour categories, hierarchical destinations, slugs, publication status, translations if required, image/media associations, display order and SEO metadata. Preserve referential integrity and use Laravel migrations, Eloquent models, policies, feature tests and later React TSX admin interfaces. Changes must not affect P2B routes, roles, audit protections or Fortify.

## Subsequent module order

1. **P3:** Categories, destinations, tour packages, itineraries, pricing, schedules and statuses.
2. **Media & gallery:** Central media library; albums, image ordering, captions, alt text and tour associations.
3. **Reviews:** Moderated traveler submissions, ratings, verified booking signals, replies, public widgets.
4. **Visual page builder:** Versioned block JSON, previews, templates and live data source definitions for tour grids, galleries, review widgets and blog lists.
5. **Header/footer builders:** Editable site chrome and responsive navigation with previews, publication and revisions.
6. **Blog & dynamic pages:** Posts, categories, rich editor, site pages, listing/detail templates.
7. **AI SEO:** Suggested metadata, structured data, sitemap and content checks, **human review required before publication**.
8. **Bookings and operations:** Inquiry/customer booking lifecycle linked to authoritative tour data.

The exact sequencing of the remaining roadmap can be refined as requirements and real content are validated. Every module must be admin-editable, builder-compatible, authorized and auditable.

## Engineering rules carried forward

- Use TSX and project-specific `resources/js/pages/` conventions.
- Keep Bablys's role and permission catalog as the authority; do not create a parallel permission system.
- Do not accept client-provided prices, dates, availability or SEO snippets as authoritative business data.
- Do not weaken immutable audit triggers, bypass staff suspension, or grant owner-only capabilities through custom roles.
- Preserve green PHPUnit/PHPStan/TypeScript/build gates at each milestone.
- Production operational approvals remain open independent of continued local development.
