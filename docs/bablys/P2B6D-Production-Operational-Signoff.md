# P2B.6D — Production Operational Signoff (Unapproved Template)

This is a **release-gate checklist**, not evidence that production protections have already been configured.

## Gate A — Database privileges and immutable audit table

- [ ] DBA confirms application runtime account **cannot** execute DDL/`TRIGGER`/`DROP`/`ALTER` operations on audit objects.
- [ ] DBA uses a distinct maintenance account for audited schema changes and trigger management.
- [ ] `cms_audit_events` UPDATE and DELETE are rejected in target DB by installed triggers; INSERT works.
- [ ] Off-host backups include **trigger definitions**, not merely table rows.
- [ ] Database version and grants are documented without publishing passwords.

**Owner:** ______  **Review date:** ______  **Evidence:** ______  **Decision:** Pending

## Gate B — Recovery / backup proof

- [ ] Approved RPO and RTO documented.
- [ ] Encrypted, versioned off-site backups exist with access monitoring.
- [ ] A backup was restored into a clean, isolated environment on a recorded date.
- [ ] Restored audit row count, schema, encryption artifacts and guard triggers were validated.
- [ ] Restore logs retained privately; non-secret summary attached to release evidence.

**Owner:** ______  **Restore date:** ______  **Evidence:** ______  **Decision:** Pending

## Gate C — Archive key custody

- [ ] `BABLYS_AUDIT_ARCHIVE_KEY` stored in a secrets manager, separate from code and backups.
- [ ] Historical keys are escrowed so old archives remain decryptable after rotation.
- [ ] Operators have approved access policies; export/verify remains manual unless a future reviewed scheduler is introduced.
- [ ] At least one older encrypted archive copied off-host, verified and recovery-tested.
- [ ] Neither archives nor manifest files are exposed through Laravel public disk or web server static paths.

**Owner:** ______  **Evidence:** ______  **Decision:** Pending

## Gate D — Privacy, retention and legal hold

- [ ] Retention windows approved by accountable business/legal owner; requirements reviewed for relevant jurisdictions.
- [ ] Legal-hold workflow and incident preservation process documented.
- [ ] Redaction and minimization verified against representative records.
- [ ] `BABLYS_AUDIT_RETENTION_DAYS` understood as **policy only**; no automatic purge exists.
- [ ] Any removal/expiry plan requires a distinct DBA change with archive proof and authorization.

**Owner:** ______  **Policy reference:** ______  **Decision:** Pending

## Gate E — Operations and web deployment

- [ ] HTTPS, proxy headers, secure cookie/session settings and application URL checked in target environment.
- [ ] Real mail transport tested for staff invitation, password reset and email verification in staging.
- [ ] Audit insert failures, archive errors, disk capacity, DB health and backup failures monitored.
- [ ] Named incident responder, escalation contact and maintenance window are documented.
- [ ] Rollback procedure accounts for intentionally non-reversible audit guard migration.
- [ ] No real credentials, database dumps, export keys or personal audit data are attached to release tickets.

**Owner:** ______  **Evidence:** ______  **Decision:** Pending

## Required final decision

**Production launch authorization:** NOT APPROVED until Gates A–E have evidence and signed owner decisions. The successful local QA reported in P2B.6C does not replace a restore drill or DBA review.
