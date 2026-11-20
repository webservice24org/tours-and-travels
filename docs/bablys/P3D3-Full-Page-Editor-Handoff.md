# P3D.3 acceptance and handoff

### API surface

- Existing `GET/POST /admin/internal/tours`, `GET/PUT/DELETE /admin/internal/tours/{tour}` extended with `tag_names` validation/response, server HTML sanitization, transaction-bound tag sync.
- `GET /admin/internal/tour-tags/suggest?q=...` — suggestions (tours.view).
- `GET /admin/internal/tours/{tour}/media` — tour-scoped media listing (tours.view).
- `POST /admin/internal/tours/{tour}/media` — multipart image upload, optional `featured` (tours.edit, extra tours.publish for already live tours).
- `PATCH /admin/internal/tours/{tour}/media/{media}` — alt text / sort order / featured (same permissions).
- `DELETE /admin/internal/tours/{tour}/media/{media}` — confirm action in frontend (same permissions); rejects deleting image still present in tour HTML.
- `GET /admin/tours/create` — Inertia page, tours.create.
- `GET /admin/tours/{tour}/edit` — Inertia page, tours.edit and tours.publish for published/scheduled.

All routes use existing auth + `EnsureCmsStaff` plus permission middleware and no-store JSON responses where relevant. TourManager retains existing transactional publish/category/destination guards and audit logs. No sidebar/layout replacement.

### Later roadmap

P3D.4 integration/QA. P3E itinerary/pricing/departure foundation. General media library will extend the tour-scoped media model with centralized file lifecycles and authorized cross-CMS associations. Do not embed unsafe HTML in public pages; output only sanitized stored HTML and apply CSP and Laravel output-escaping as appropriate.


### Installer compatibility

P3D.3 v2 checks each numbered patch anchor, allowing whitespace-only differences. It still fails closed for semantic changes. Error messages identify the numbered anchor and target file to assist a targeted manual merge; it never automatically clobbers custom code.
