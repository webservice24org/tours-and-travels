# P2A — Route & Builder Contracts (Bablys Only)

## Route ownership

| Audience | Planned paths | Guard |
|---|---|---|
| Public | `/`, `/tours`, `/tours/{slug}`, `/destinations/{slug}`, `/blog/{slug}`, `/galleries/{slug}`, CMS pages on an approved route map | published content only |
| Auth/customer | existing `/login`, `/register`, `/dashboard`; later `/account/requests` | existing auth; self-only resources |
| Staff | `/admin`, `/admin/tours`, `/admin/categories`, `/admin/destinations`, `/admin/media`, `/admin/galleries`, `/admin/reviews`, `/admin/pages`, `/admin/builders`, `/admin/seo` | auth + permission policy |
| Staff previews | `/admin/previews/{resource}` or signed staff-only preview endpoints | signed + authorized + expiring |

**Do not register `/admin` or new public CRUD routes during P2A.** First inspect the current app route list to avoid reserved-name collisions; protect existing homepage/auth.

## Versioned builder document (proposed)

The sample below is a future data contract, NOT a currently rendered or saved page:

```json
{
  "schema_version": 1,
  "blocks": [
    {
      "id": "featured-international",
      "type": "tour_grid",
      "props": {
        "heading": "International Tour Packages",
        "columns": { "mobile": 1, "tablet": 2, "desktop": 3 }
      },
      "data_source": "published_tours"
    }
  ]
}
```

Published-only queries use an allow-listed repository and allowed filter structure. Never inject raw SQL, arbitrary order expressions, executable HTML or JavaScript into block definitions. The TSX renderer is a mapping from verified `type` to approved components. Validate shape, per-type props, source permissions, category restrictions, maximum rows, sort/filter allowlists, target publication and accessibility metadata **before P7 public rendering**.

## Dynamic examples

- Create a tour in `travel_tours`, assign `travel_tour_category` and `travel_tour_destination`, approve/publish it, then choose a `tour_grid` block with `data_source: published_tours` and a safe category filter.
- Create `cms_gallery_albums` and ordered `cms_gallery_items`; use a `gallery_grid` or `gallery_lightbox` block that reads only approved public assets.
- Submit a review to `travel_reviews` in `pending`, moderate it to `approved`, and then expose it through `review_list` / `review_summary`. Rejected and hidden reviews must never count toward averages.
- Create page, header, and footer template revisions independently. Assign only published template versions and do not let a draft header accidentally replace the live header.

## DTO conventions

- PHP domain layer exposes versioned, typed read DTOs; React receives plain JSON scalar/array structures, not entire Eloquent models.
- Date/time values are UTC ISO 8601 plus original IANA timezone where public travel scheduling needs local time.
- Price DTO `{ currency: "BDT", amount_minor: 2500000, formatted: "৳25,000" }` illustrates a theoretical example, **not an actual Bablys package price**.
- Tour lists use paginated responses with safe maximum limit; no arbitrary client-defined DB selects.
- Every public resource exposes `canonical_url`, `publication_status` where appropriate, `updated_at`, and approved media references without private paths.
- Never place booking contact data or unpublished reviews in global `HandleInertiaRequests` shared props.

## Current P2A code

`PublicationStatus`, `ReviewStatus`, `BuilderTemplateKind`, and `BlockRegistry` are only architecture-level PHP contracts. `BlockRegistry::validateDocument()` intentionally validates only the outer version/IDs/type/source shape; it is **not a complete validator, sanitizer or renderer**. Per-block rules and query execution must be built and tested in P7.
