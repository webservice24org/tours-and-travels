# Bablys Tours & Travels — P2B.6C Full Integration & Regression QA

**Scope:** Bablys Tours & Travels CMS (Laravel 13 / Inertia React TSX) only  
**Release checkpoint:** P2B.6C; P2B.6D sign-off follows  
**Change model:** additive tests and documentation; **no production code/migrations/routes/data changes**

## 1. Acceptance matrix

| Area | Automated evidence | Browser / operations acceptance |
|---|---|---|
| Public site | `/` resolves `public/home`; routes for login and registration | Home visually renders without regressions, responsive checks |
| Authentication | Fortify password reset and verification routes registered; existing feature suites rerun | Login, registration, password reset email, email verification, profile security |
| Customer isolation | Customer dashboard still accessible; customer forbidden in CMS | Non-staff cannot open `/admin`, including after direct URL navigation |
| Super Admin | All four Inertia admin surfaces render; authorization on routes | `/admin`, `/admin/staff-users`, `/admin/roles`, `/admin/audit-logs` |
| Staff onboarding | Pending staff, reset notification, no password/token response, email unverified | Email link and verification complete via actual mail transport in staging |
| RBAC and roles | Create custom role; assign to staff; change permissions; Gate changes immediately | Grouped permission UI, system roles read-only, staff form options |
| Suspension | Gate denied immediately; no access; restoration succeeds; audit events | Browser session with suspended staff loses CMS access |
| Rejected changes | Cannot assign Super Admin or invalid role; no partial write or audit | Attempt privilege escalation using direct API calls is denied |
| Audit trail | Owner-only; redacted event detail; metadata omitted from list | Search, filter, pagination, detail, no secret disclosure |
| DB immutability | SQL UPDATE/DELETE attempts rejected by audit triggers | DBA confirms trigger grants and least privilege in target environment |
| Encrypted archive | AES-GCM export + verify; live rows remain; plaintext absent | Private directory; protected key; off-site backup; restoration drill |
| Operational checks | Readiness reports missing-key failure without deleting audit rows | Confirm retention/legal hold, backups, monitoring, and environment secrets |
| Performance | Existing P2B.5D query-bound and pagination tests rerun | Representative dataset; record p95, CPU, errors and slow queries |
| Quality gates | Full PHPUnit; PHPStan; TS typecheck; Vite build | Browser desktop/mobile and actual deployment smoke tests |

## 2. Required local commands

```powershell
cd C:\xampp\htdocs\bablys-travel

php artisan test --filter=CmsReleaseCrossModuleJourneyTest
php artisan test --filter=CmsReleaseAuditOperationalTest
php artisan test --filter=CmsReleaseRouteAndUiContractTest

php artisan test
vendor\bin\phpstan analyse
npm run types:check
npm run build
php artisan route:list --path=admin -v
php artisan bablys:audit:readiness
```

**Database safety:** use an isolated disposable database for all `php artisan test` commands. The `RefreshDatabase` trait can migrate and reset database state. Check `phpunit.xml` and `.env.testing` against local credentials. Never run tests against the real Bablys website database.

Readiness may show environment-related failures (archive key, trigger visibility, storage directory) on one machine while unit tests are green; fix or document these before production approval. `bablys:audit:readiness` does **not** validate off-site backups or restore drills.

## 3. Manual browser QA (record pass/fail and screenshots where practical)

- [ ] Public homepage `/` on mobile, tablet, desktop; no horizontal overflow.
- [ ] Register a new customer; check no CMS access.
- [ ] Login/logout, wrong password handling, reset link, email verification, password update.
- [ ] Owner views admin, staff, roles, and audit logs.
- [ ] Owner creates staff, sends invitation, completes actual mail and verification in staging.
- [ ] Owner creates a custom Tour/Content role and assigns appropriate non-owner permissions.
- [ ] Staff's effective access follows custom-role permission removal without re-login.
- [ ] Suspension instantly denies a currently authenticated staff session; restoration recovers allowed access.
- [ ] Role management rejects protected/system-role changes and unsafe owner-only grants.
- [ ] Staff and role changes appear in audit feed, details are redacted.
- [ ] Audit search, filters, pagination, and mobile layout work as expected.
- [ ] Non-owner cannot open audit logs even after a permission-pivot tampering simulation **only in a test environment**.
- [ ] Archive command preview does not write; signed/encrypted export and verification pass on a historical **test** date.
- [ ] Confirm no HTTP UPDATE/DELETE routes for audit history.

## 4. Production readiness gates that tests cannot prove

- [ ] Production credentials do not permit schema/trigger changes; separate DBA maintenance account exists.
- [ ] Encrypted, access-controlled off-site backups exist and a **restore drill has actually succeeded**.
- [ ] Archive encryption key rotation, escrow and recovery are documented and tested.
- [ ] Audit retention period, minimum records, privacy requirements and legal holds approved.
- [ ] Trigger install privileges, runtime ability to inspect trigger inventory and SQL portability evaluated for the production DB engine.
- [ ] Retention remains policy-only; **no automatic archive purge** is deployed by P2B.6B or P2B.6C.
- [ ] DB/log/archive capacity thresholds and alert routes assigned to an operator.
- [ ] Reverse proxy, HTTPS, trusted headers, session/cookie protections, queues, mail, error monitoring reviewed.
- [ ] Responsive QA and real-browser email onboarding completed in staging.
- [ ] Deployment rollback and response owner documented; database triggers require a separate guarded rollback strategy.

## 5. Sign-off record (complete after running checks)

| Gate | Result | Evidence / notes |
|---|---|---|
| New P2B.6C tests (15) | Pending | |
| Full Laravel regression suite | Pending | |
| PHPStan | Pending | |
| TSX type check | Pending | |
| Production build | Pending | |
| Admin route inventory | Pending | |
| Audit readiness | Pending | |
| Browser QA | Pending | |
| Operations and data governance | Pending | |

**Decision:** Do not mark P2B.6C complete until local gates and browser checks are green. Do not treat P2B.6D or production launch as approved until the explicit outstanding operational gates above have an accountable owner and evidence.
