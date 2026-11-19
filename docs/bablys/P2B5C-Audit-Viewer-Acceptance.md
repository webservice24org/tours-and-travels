# Bablys P2B.5C — Read-only Administrative Audit Viewer

## Scope and prerequisites

This milestone belongs exclusively to **Bablys Tours & Travels** (Laravel 13 + Inertia/React TSX). Prerequisites: P2B.1–P2B.4C RBAC and admin authorization; P2B.5A `cms_audit_events` migration, recorder, redactor; P2B.5B audit mutation integration. **No new database tables or seed changes**. Existing writers are not modified.

## Routes and authorization

| Method | URL | Description |
|---|---|---|
| GET | `/admin/audit-logs` | Inertia audit viewer |
| GET | `/admin/internal/audit-logs` | Paginated, filtered metadata-free summary feed |
| GET | `/admin/internal/audit-logs/options` | Distinct action and subject-type choices |
| GET | `/admin/internal/audit-logs/{event}` | Full single-event redacted detail (`UUID`) |

All use `auth`, `EnsureCmsStaff`, `EnsureCmsPermission:audit.view`, rate limiting, plus a **second server-side check** for active verified `super_admin` membership (system role). A mere accidental grant of `audit.view` to another role does not expose the viewer. There are no POST, PATCH, PUT or DELETE audit routes.

## Feed response

Each summary row includes: event UUID, action, actor ID and display name (with `Deleted account #ID` fallback), subject type and ID, UTC ISO-8601 timestamp. **The summary excludes metadata, IP, and user agent**. Detail includes `metadata`, IP and user agent only for owner-authorized requests.

Filter query keys: `search` (up to 100 characters), `action`, `actor_id`, `subject_type`, `subject_id`, `date_from`, `date_to` (UTC `YYYY-MM-DD`), `page` and `per_page` (10, 25, 50). All values validated server-side. Pagination uses `occurred_at DESC, id DESC` for stable ordering. Name search uses actor's current name and the subject ID; deleted actors retain the numeric ID.

Metadata display is defense-in-depth: the existing `CmsAuditRedactor` runs again at read time, and **only reviewed P2B.5B keys** are exposed: `role_key`, `role_keys_before`, `role_keys_after`, `permission_keys_before`, `permission_keys_after`, `changed_fields`, `enabled_before`, `enabled_after`, `email_verified`, `email_verification_cleared`. Unknown historical keys are suppressed instead of blindly displayed. Extend this allowlist only when new audit event contracts are reviewed.

## Operational guarantees and limitations

- Existing audit event insert-only service remains unchanged. The viewer itself never writes to `cms_audit_events`.
- Browser/React controls are not authorization: endpoints check permissions and owner identity server-side on every request.
- Audit actor names are joined at read time and may change; numeric actor IDs persist when user records are deleted.
- Audit IP and user-agent can be personal data. Production retention and lawful use, database-level append-only grants, backup integrity and export policy belong to P2B.5D.
- No file export is shipped in this milestone. Avoid unreviewed mass disclosure of PII.
- Search is a bound SQL `LIKE` text search; wildcard characters are treated as wildcards. This is not an unrestricted SQL query builder.

## Acceptance checklist

- [ ] Install via the PowerShell 5.1-safe `-WhatIf` preview, then actual run.
- [ ] `/admin/audit-logs` opens for a verified active Super Administrator.
- [ ] Guest, customer, ordinary staff and suspended staff are denied.
- [ ] Accidental `audit.view` grant to non-owner still fails owner check.
- [ ] Listing only includes summaries; event details are read-only and redacted.
- [ ] Search, action, actor, subject, date filters, pagination and deleted actor fallback work.
- [ ] No write audit HTTP routes exist.
- [ ] `php artisan test --filter=CmsAuditViewerTest` passes in disposable test database.
- [ ] Full `php artisan test`, `vendor\bin\phpstan analyse`, `npm run types:check`, `npm run build` pass.
- [ ] Browser confirms no mobile horizontal overflow and no accidental sensitive data.
