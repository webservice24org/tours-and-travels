# Bablys P4A.1 — Global Site Settings Foundation

## Delivery scope

- Existing `settings.manage` RBAC gate; no new roles, no bypass via client-side UI.
- Singleton `bablys_site_settings` plus immutable-per-version draft revision rows.
- Typed, whitelisted site name/tagline/legal name, public contact details, social URLs and legal links.
- Admin draft-only editor at `/admin/site-settings` using the existing Bablys admin layout.
- Public JSON projection `/api/public/site-settings` reads **published** settings only. Before publishing is added, it safely returns defaults.
- Optimistic draft revision check prevents one editor silently overwriting another editor's changes.

## Critical boundary

P4A.1 does **not** publish. Saving a draft does not modify the public website header/footer or affect bookings. P4A.2 adds menu management; P4A.3 adds audited preview/publish/rollback; P4A.4 binds the published settings to public components and performs final tests.

This is intentional: draft content must not replace an already published website without authorization and an explicit publication action.

## Manual checks

1. As a guest or ordinary customer, `/admin/site-settings` is not accessible.
2. As staff without `settings.manage`, access remains forbidden.
3. As super admin, open the site-settings editor and save two consecutive drafts. Verify revision numbers increment.
4. Open a second tab, save there, then attempt to save a stale draft from the first tab: the old version must be rejected and not overwrite the newer draft.
5. Enter unsafe URL/HTML values: validation must reject them. Social and legal links must be full HTTPS URLs.
6. Open `/api/public/site-settings`: it must still show the published/default settings, never private drafts, actor IDs, audit revisions, encrypted secrets or account details.
7. Run `php artisan test --filter=GlobalSiteSettingsFoundationTest` and the complete suite on the isolated MySQL test database. Run PHPStan, TS type check and build in the normal development CMD.

## Operational notes

- No existing route, booker, pricing, gallery, admin sidebar or public TSX file is replaced.
- Navigation link to this editor can be added in P4A.2 after inspecting the exact existing navigation source; the editor is reachable at `/admin/site-settings` now.
- Do not insert API keys, SMTP credentials or secrets into these public-display fields. Sensitive configuration belongs to a separate encrypted settings service.
- Do not run database-refresh tests against the development or race database.
