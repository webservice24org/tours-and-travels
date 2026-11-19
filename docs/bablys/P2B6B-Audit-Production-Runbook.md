# Bablys Tours & Travels — P2B.6B Audit Production Readiness

**Project:** `C:\xampp\htdocs\bablys-travel` (dedicated Laravel 13 / Inertia React TSX CMS). Never reuse this plan in the user's news, e-commerce or ERP applications.

## What this package actually implements

1. Database `BEFORE UPDATE` and `BEFORE DELETE` triggers on `cms_audit_events`, using MySQL/MariaDB `SIGNAL SQLSTATE '45000'` or SQLite `RAISE(ABORT)`. The existing recorder can still `INSERT` events. Changes to users/roles and corresponding audit writes still share the existing transaction.
2. An **operator-only CLI**, `bablys:audit:archive`, that previews or exports a **complete older UTC day** into a *private*, per-record AES-256-GCM encrypted JSONL file with a signed HMAC-SHA-256 manifest; it supports verification of an existing file. It **never deletes live audit rows**.
3. `bablys:audit:readiness` reports trigger visibility, encryption-key presence, archive path safety, and policy thresholds. It **cannot** assert that off-site backups, operating-system ACLs, or restore tests are in place.
4. 12 focused feature tests, covering immutability, encrypted archive roundtrip, checksums, duplication, key requirements and boundary validation.
5. A surgical update to one old privacy test: inject legacy metadata with an INSERT fixture instead of forbidden UPDATE. The original allowlist/redaction assertion remains unchanged.

There are **no new HTTP endpoints, scheduled jobs, React UI changes, role grants, or automatic deletion/retention sweeps**. Retention is documented as a policy target, NOT an active purge mechanism.

## Critical design decision: retention vs immutability

The operational database intentionally rejects **all** UPDATE and DELETE attempts on the audit table. This includes cleanup attempts by application code. The `BABLYS_AUDIT_RETENTION_DAYS` setting is for planning and inventory only. This package does not execute retention deletions or attempt a hidden bypass in the triggers. Before any eventual purge:

- Obtain a documented retention and legal-hold policy, including jurisdiction-specific personal-data handling and required preservation periods.
- Ensure the affected UTC days have **verified encrypted archives**, off-host immutable copies and an approved recovery test.
- Design a separately authorized DBA procedure using a maintenance account and change approval. Application/database runtime accounts must never hold permissions to drop or replace triggers.
- Preserve written evidence of what was archived and removed. Archive keys must remain available for decryption throughout the retention period.

**Immutability is defense-in-depth, not cryptographic tamper-proofing.** A powerful DBA, filesystem administrator, compromised server or a restore from a malicious backup can bypass these controls. Use least privilege, separate DBA credentials, independent monitoring, immutable off-site backups and periodic restore tests.

## Before you install or migrate

1. Back up the **entire** Bablys MySQL database using an approved encrypted backup system. Record the backup timestamp, checksum, storage location and who verified restoration; verify backup captures **triggers**. Never store database dumps or secrets in `public/`, source control, or the downloadable ZIP.
2. Review that the application has its own account separate from the migration account. For production, runtime access should not include DDL permissions such as `DROP`, `ALTER`, `CREATE`, or `TRIGGER`; restrict database-level privileges as far as your schema permits. A root/superuser DB credential in Laravel `.env` is **not acceptable for production**.
3. Confirm `cms_audit_events` exists (P2B.5A) and P2B.5B/C/D/6A have passed locally.
4. Confirm your **test** connection uses a disposable database. `RefreshDatabase` will reset the selected test database. Never point tests at `bablys_travel` production data.
5. Read the migration carefully. It requires `TRIGGER` privilege for MySQL/MariaDB and will fail closed for unsupported DB drivers.

### Configure the archive (Windows PowerShell)

Generate a new 32-byte **dedicated** key locally (do not paste it into chat or commit it):

```powershell
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Set in your private `.env`:

```dotenv
BABLYS_AUDIT_ARCHIVE_KEY=base64:<secret-generated-value>
BABLYS_AUDIT_ARCHIVE_AFTER_DAYS=90
BABLYS_AUDIT_RETENTION_DAYS=730
# Optional: absolute private path outside public; defaults to storage/app/private/bablys-audit
# BABLYS_AUDIT_ARCHIVE_DIRECTORY=C:\secure\bablys-audit
```

Your `.env` should **not** be committed. Save the archive key securely in an independent secrets manager; losing the key makes existing archives unreadable, and rotating it requires retaining historical keys. Ensure app, DB session and queue timestamps are consistently UTC before scheduled archival.

## Apply migrations and verify

```powershell
cd C:\xampp\htdocs\bablys-travel
php artisan migrate:status
php artisan migrate --pretend
php artisan migrate
php artisan optimize:clear
php artisan bablys:audit:readiness
```

`bablys:audit:readiness` may report missing/unreadable triggers when run as a least-privilege runtime MySQL account. Use a privileged **maintenance** session for trigger inventory inspection; this does not justify granting the web runtime `TRIGGER` privilege. Also inspect `SHOW TRIGGERS LIKE 'cms_audit_events'` with a DBA account.

**Fail-closed migration rollback:** `down()` deliberately refuses to remove the guard triggers automatically. If MySQL creates one trigger but the next statement fails, stop, inspect both triggers through the DBA account, and follow a manual, reviewed repair. Do **not** remove triggers or alter migration records blindly to make an installation green.

## Archive operations (safe and explicit)

```powershell
# Dry-run by default; never writes a file
php artisan bablys:audit:archive 2025-06-01

# With a verified dedicated key, export an older day (older than 90 days by default)
php artisan bablys:audit:archive 2025-06-01 --commit

# Verify encrypted file + signed manifest (including every GCM record)
php artisan bablys:audit:archive 2025-06-01 --verify
```

The exporter refuses newer dates, unsupported keys, public web paths, missing rows, and overwriting existing archive files. The archive contains personal/sensitive operational data **in encrypted form**; restrict access to authorized operators and move both the `.encjsonl` and `.manifest.json` together to your protected off-site destination. Neither the file nor the manifest should be served over HTTP.

A `.lock` file may remain after export; it is non-secret and prevents concurrent exports of the same day. A crash between the two final renames can leave an encrypted archive with no manifest; this is **not** automatically overwritten. Quarantine and investigate partial artifacts, then restore from an approved recovery procedure. Do not delete or replace an existing archive to force a rerun.

**Limitation:** Export is per complete UTC day. The service selects all existing events for that day and verifies the serialized record count/cryptographic integrity. It does not yet integrate with your off-site storage, cross-region replication, scheduling, SLA alerting, or legal-hold catalog. These must be reviewed before calling the system production-ready.

## Backup and restoration acceptance checklist (operator sign-off)

- [ ] A recovery point objective (RPO) and recovery time objective (RTO) have been approved.
- [ ] Encrypted full-database backups include audit data, migrations, trigger definitions and required schema.
- [ ] Backup credentials and encryption keys are stored separately from the Laravel web-runtime account.
- [ ] The backup location is off-host, access-controlled, versioned/immutable, and monitored.
- [ ] A restore into a new isolated database was performed and dated; audit row counts and protected triggers were checked.
- [ ] An older archive `.encjsonl` and `.manifest.json` were copied off-host and verified using the retained historical key.
- [ ] Application DB grants are least privilege; DBA/migration credentials are not in the web server runtime environment.
- [ ] Monitoring exists for audit insert failures, archive verification failures, disk growth, clock drift and backup failures.
- [ ] Retention/legal-hold/privacy policy is approved. **No automatic deletion is enabled.**
- [ ] Production TLS, secrets manager, host access logging and file ACLs are independently verified.

## QA commands

```powershell
php artisan test --filter=CmsAuditImmutableDatabaseTest
php artisan test --filter=CmsAuditArchiveProductionTest
php artisan test --filter=CmsAuditPrivacyBoundaryTest
php artisan test
vendor\bin\phpstan analyse
npm run types:check
npm run build
```

Run the whole suite against a disposable testing DB. Verify that existing `/admin/staff-users`, `/admin/roles`, `/admin/audit-logs`, public homepage, login, and dashboard remain functional. Mark P2B.6B green only after these checks and an agreed production-operations review.
