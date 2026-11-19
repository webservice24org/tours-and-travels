# Bablys Tours & Travels — P2B.6A Final RBAC & Authorization Security Review

**Scope:** Laravel 13 / Inertia React TSX Bablys CMS only. This is the **review and automated guardrail** portion of P2B.6. It is not a production-security certification.

## Decision and baseline

The local owner reported that P2B.1–P2B.5D passed, including role management, live revocation, the audit viewer and audit privacy/performance QA. This pack adds 17 focused checks for the final authorization boundary. The package **does not change application code, roles, permissions, migrations, seed data, routes, services, policies, or Vue/React code**. It also does not reassign any administrator.

- **Canonical policy source:** `App\Domain\Cms\Access\RbacCatalog` declares 7 system roles and 28 permissions.
- **Runtime authorization:** `CmsAccess` + Laravel Gates; unknown permissions are denied. Effective grants are looked up against database state on each check rather than cached in sessions.
- **Owner identity:** Staff Users, Roles, and Audit Logs require a granted ability **and** a genuine `super_admin` assignment (second check in their domain/view services). The routes also apply authenticated CMS middleware.
- **Suspension:** `users.cms_staff_disabled_at` must deny every CMS access path immediately, including for already-authenticated users.
- **Email verification:** Unverified staff must not enter CMS privileged surfaces.
- **Audit scope:** Audit history is read-only via HTTP; read and detail endpoints enforce `audit.view` and super-admin membership.

## Automated suite — 17 tests

### A. `CmsFinalRbacContractTest` — 6

1. All 28 catalog permissions have distinct Laravel Gate registrations.
2. Ordinary customer has no roles or CMS privileges; unknown abilities deny.
3. All seven seeded system roles have **exactly** the grants in the canonical matrix on a fresh test DB.
4. A role is never auto-assigned to a newly created user; explicit owner assignment unlocks the catalog grants.
5. Seeder reruns don't re-grant a deliberately removed system permission.
6. Changing a permission to a non-admin scope changes Gate results immediately and is reversible.

### B. `CmsFinalCrossSurfaceAuthorizationTest` — 7

1. Guests denied across admin home, staff, roles and audit.
2. Ordinary registered customers denied across those surfaces.
3. Content editors can enter CMS but not owner-only modules.
4. Tampering with a non-owner role's pivot grants still cannot open/operate staff, roles or audit; rejected role creation has no side effect.
5. Suspending a super-admin account revokes access on subsequent requests without refreshing the in-memory user model.
6. Removing owner email verification revokes CMS entry immediately.
7. A valid owner can read all three admin modules and their list endpoints.

### C. `CmsFinalRouteSecurityInventoryTest` — 4

1. Every currently registered `/admin` route requires both authentication and `EnsureCmsStaff`.
2. Critical named staff/roles/audit endpoints include their required `EnsureCmsPermission` middleware.
3. Audit JSON history endpoints register no POST, PUT, PATCH, or DELETE routes.
4. High-trust writes and the audit feed use request throttling.

**Interpretation of test failures:** Do not weaken the test expectation to make it pass. Inspect route registration, middleware nesting, any manually modified RBAC rows, and the responsible controller/domain service. If a newer application version legitimately changes a route name, review the new route and update inventory assertions with evidence.

## Manual browser security signoff

- [ ] Sign in as the **Super Administrator**: `/admin`, `/admin/staff-users`, `/admin/roles`, `/admin/audit-logs` all load.
- [ ] Sign in as a **regular registered customer**: all `/admin` pages deny access.
- [ ] Sign in as a **content editor**: `/admin` loads, owner-only modules deny access.
- [ ] Create a custom `custom_...` role with safe tour/gallery permissions; assign to a staff member; test only approved capabilities.
- [ ] Attempt to assign `roles.manage`, `settings.manage`, or `audit.view` to a custom role: rejected, with no partial database writes.
- [ ] Attempt to assign the `super_admin` role via Staff Users: rejected.
- [ ] Revoke a staff role, change its permissions, and suspend the account: privileges disappear on the **next request**, without a new login.
- [ ] Restore a permitted active/verified account; only its remaining role grants return.
- [ ] Review audit events for staff/role mutations, including responsible actor and curated before/after changes; no passwords/tokens present.
- [ ] Verify public `/`, `/login`, `/register`, and `/dashboard` still work as before.
- [ ] Review role/permission changes when two owner browser sessions are open; stale UI must not bypass server validation.
- [ ] Review server logs after a deliberately rejected permission request; no sensitive payload should be logged.

## Production security findings / forward controls

These are **not implemented or guaranteed** by this tests-only milestone:

1. **Database tampering by privileged SQL actors:** A direct out-of-band DB write may make a raw permission Gate reflect forged grants. Staff/role/audit endpoints have second owner-identity checks, but database write permissions should be restricted and every **future** sensitive endpoint must also enforce identity where needed.
2. **Audit immutability beyond HTTP:** No database-level append-only restrictions, cryptographic hash chaining, off-host replication, or managed immutable backup is included yet. Scope and approve these in P2B.6B.
3. **Retention and privacy policy:** Approve retention windows, access/export controls, backup encryption, deletion/legal hold procedures and IP/user-agent minimization before production deployment.
4. **Rate limits and monitoring:** Verify reverse-proxy limits, login throttling, notifications for privilege changes, brute-force monitoring and mail setup in the target hosting environment.
5. **Secrets and least privilege:** Review production `.env`, SQL user privileges, encryption keys, TLS, session/cookie flags, mail credentials, deployment access, and least-privilege staff roles.
6. **Local files are not the installed repository:** The QA tests are grounded in the earlier Bablys packages. The user's working project may include later edits; local test and human review are authoritative.

## Verification gates

Run against a **separate, disposable test database only**. Laravel `RefreshDatabase` can reset tables.

```powershell
cd C:\xampp\htdocs\bablys-travel
php artisan test --filter=CmsFinalRbacContractTest
php artisan test --filter=CmsFinalCrossSurfaceAuthorizationTest
php artisan test --filter=CmsFinalRouteSecurityInventoryTest
php artisan test
vendor\bin\phpstan analyse
npm run types:check
npm run build
php artisan route:list --path=admin -v
```

Completion requires all three new groups and the full regression suite green, no PHPStan/TypeScript/build errors, plus browser checks. **Then mark P2B.6A complete** and proceed to **P2B.6B — Audit Security & Production Readiness**. P2B.6 as a whole remains open until subsequent milestones pass.

**Project boundaries:** Bablys only; never reuse the project-specific RBAC structure or route names in the user's news, e-commerce, or corporate ERP projects.
