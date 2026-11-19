# Bablys Tours & Travels — P2B.5D Audit Security, Privacy & Performance QA

**Project:** `C:\xampp\htdocs\bablys-travel` (Laravel 13 + Inertia React TSX)  
**Dependency:** P2B.5A (schema/redactor/recorder), P2B.5B (event writers), P2B.5C (read-only audit viewer), all locally green.  
**Change type:** Additive feature tests + documented operations checklist. No production code changes, migrations, or seeders.

## Automated regression matrix

| Test class | Cases | Checks |
|---|---:|---|
| `CmsAuditPrivacyBoundaryTest` | 8 | Guest/customer/editor denied across surfaces; accidental permission grant doesn't make owner; live suspension/verification/role revocation; metadata-less listing; nested at-rest redaction; historical allowlist/read-time redaction |
| `CmsAuditIntegrityQaTest` | 7 | Owner cannot edit/delete history over HTTP; read requests do not write; transaction rollback removes event; invalid action and oversized metadata rejected; duplicate no-op writes suppressed; historical subject reference retained |
| `CmsAuditPerformanceFiltersQaTest` | 8 | 225-row page sizing; 320-row bounded SQL query count (no N+1); migration indexes; filter validation; SQL search injection is bound; UTC end-only date; same-time UUID order; distinct metadata-free options |
| **Total** | **23** | In addition to all earlier P2B.5A–5C test suites |

## What the automated tests do NOT prove

- There is **no database-level append-only permission guarantee** yet. The app exposes no audit mutation routes, but a database administrator or a compromised database account may still modify rows. Decide whether to separate insert-only writer credentials and read-only viewer credentials, and whether to use database triggers or an external immutable log sink.
- A passing query-count assertion is not a production latency benchmark. For production performance, inspect MySQL `EXPLAIN`, live row counts, indexes, and query latency; avoid guesswork and hardcoded millisecond targets.
- No production data-retention period or deletion schedule is configured by this package. Determine the retention and access policy with the business owner and applicable legal requirements before deployment.
- Hash chaining, signed exports, WORM backups, deletion retention controls, and immutable off-site copies are not installed in this milestone.

## Manual security and privacy acceptance checks

- [ ] Use a **disposable testing database** for all `RefreshDatabase` tests. Verify `phpunit.xml` / `.env.testing`; never point it at `bablys_travel` production/primary data.
- [ ] A verified, active Super Administrator can open `/admin/audit-logs`, inspect a detail, and apply date/action/actor filters.
- [ ] A regular customer, CMS editor, unverified owner, and suspended owner cannot see the page, feed, options, or details.
- [ ] Accidentally adding `audit.view` to a non-owner role **does not** bypass the additional real `super_admin` identity check.
- [ ] Open browser Network tab: list API never includes `metadata`, `ip_address`, or `user_agent`; details only contain curated/redacted metadata.
- [ ] Changing staff roles and custom-role permissions writes exactly one appropriate audit event; repeat an identical update and confirm no new event.
- [ ] A failed transactional mutation writes **neither** a business change **nor** an audit event.
- [ ] Audit history is read-only through the web interface: no POST, PATCH, PUT, DELETE or bulk export endpoint.
- [ ] Verify no tokens, passwords, reset links, API keys, payment details, or unapproved personal information are present in new or historical event metadata.
- [ ] Verify authorized admins understand that IP address, user-agent and user IDs are personal/security data, and restrict who can view them.
- [ ] Pagination works on a large testing dataset without loading all records into the browser.
- [ ] Review query plans and index usage in MySQL for `actor_id+occurred_at`, `action+occurred_at`, and `subject_type+subject_id+occurred_at`.
- [ ] Verify audit table backups and a tested restore process. Agree on retention, access logging, and immutable backup requirements prior to production launch.
- [ ] Confirm staff-management, roles-management, authentication, public homepage, tests, PHPStan, TypeScript and production build remain green.

## Commands

```powershell
cd C:\xampp\htdocs\bablys-travel

php artisan test --filter=CmsAuditPrivacyBoundaryTest
php artisan test --filter=CmsAuditIntegrityQaTest
php artisan test --filter=CmsAuditPerformanceFiltersQaTest
php artisan test --filter=CmsAuditViewerTest
php artisan test --filter=CmsAuditEventIntegrationTest
php artisan test
vendor\bin\phpstan analyse
npm run types:check
npm run build
```

## QA signoff

- **P2B.5D:** Prepared, pending local tests and browser/operational review.
- **P2B.6:** Final RBAC/Audit security regression closure **not started**.
- On a failure, preserve the failing assertion; fix the Bablys implementation at the correct layer, rather than weakening permissions or disabling PHPStan checks.
