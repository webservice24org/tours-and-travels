# Bablys Tours & Travels — P2B.4C Roles & Permissions Security Closure

**Scope:** Bablys Laravel 13 + Inertia React TSX project only. This milestone adds regression tests and acceptance guidance. It does not modify production PHP code, routes, migrations, seeders, JavaScript, or database values during installation.

## Automated test matrix (19 new tests)

### Security boundaries — `CmsRolesSecurityBoundaryTest` (7)

| Test | Security invariant |
| --- | --- |
| Guests cannot open UI or use role/staff APIs | Unauthenticated callers cannot browse or modify privileged records |
| Customers and regular staff denied | Mutations are owner-only, even if content access is permitted |
| Forged `roles.manage` on non-owner denied | Domain-level owner identity survives accidental permission grants |
| System-role flag tampering still immutable | Keys in code-owned system catalog cannot be modified or deleted |
| `is_system` and owner-only permissions rejected | Client input cannot produce owner-level custom roles |
| Permission catalog marks dangerous grants | UI options report nonassignable `roles.manage`, `settings.manage`, `audit.view` |
| Owner UI/API data safe | Correct roles Inertia component; no password/token in roles JSON |

### Live authorization — `CmsRolesLiveRevocationTest` (6)

| Test | Security invariant |
| --- | --- |
| Swap permission grants | Existing staff loses old Gate grant and receives new one immediately |
| Revoke final grant | Admin access closes, role membership remains available for later repair |
| Suspend and restore | Gate and HTTP access react to database staff status; no stale model bypass |
| Unverify email | CMS permissions denied even when role mapping remains |
| Multiple roles | Removing one role's grant does not remove independent grants |
| Remove/reassign roles | Empty list demotes staff to customer; subsequent safe role assignment restores authorized access |

### Integrated workflow — `CmsRolesWorkflowIntegrationTest` (6)

| Test | Security invariant |
| --- | --- |
| Create role -> UI option -> assign -> Gate | Custom roles are usable end-to-end without hardcoded role lists |
| Assigned role cannot be deleted | Force reassignment before role deletion |
| Unsafe custom role DB tampering cannot be assigned | Role resolver rejects owner-only grants independently of request validation |
| Malformed requests are atomic | Duplicate/unknown keys and super admin escalation leave original grants intact |
| Role index filtering | Search filters custom roles and preserves immutable system roles |
| Public homepage remains intact | Bablys public `/` still returns the expected Inertia component |

## Manual browser acceptance (perform as real users)

1. Owner: open `/admin/roles`, create a custom role with `tours.view` and `galleries.edit`, then assign it from `/admin/staff-users`.
2. Staff: log in, confirm `/admin` is accessible, while `/admin/roles` and `/admin/staff-users` remain forbidden.
3. Owner: remove the staff member's grants. Staff access must be denied on the next request without requiring a new login.
4. Owner: restore safe grants; verify access returns only for those capabilities.
5. Owner: suspend a staff account; verify access is denied; restore it and verify again.
6. Owner: confirm the seven system roles are read-only and a custom role cannot receive `roles.manage`, `settings.manage`, or `audit.view`.
7. Owner: confirm an assigned custom role cannot be deleted until its staff assignments are removed.
8. Test responsive role editor (mobile width), search, permission checkboxes, empty states, errors and success feedback.
9. Re-check `/`, login, registration, `/dashboard`, `/admin`, `/admin/roles`, and `/admin/staff-users`.
10. Verify there are no new high-privilege grants in the database after rejected requests.

## Automated quality gates

Run against a **separate disposable testing database only**. Do not run tests with `RefreshDatabase` against the real `bablys_travel` database.

```powershell
cd C:\xampp\htdocs\bablys-travel
php artisan test --filter=CmsRolesSecurityBoundaryTest
php artisan test --filter=CmsRolesLiveRevocationTest
php artisan test --filter=CmsRolesWorkflowIntegrationTest
php artisan test
vendor\bin\phpstan analyse
npm run types:check
npm run build
php artisan route:list --path=admin -v
```

Success criteria: all new security tests, existing staff and role tests, full Laravel suite, PHPStan, TypeScript and frontend build pass; manual checks show no privilege escalation and no stale grants. If a test fails, investigate the authorization defect; do **not** change the expected result just to make the suite green.

## Known architecture boundary

The P2B owner-only domain services provide a second authorization barrier beyond named Laravel Gates. A directly tampered `cms_permission_role` database row might make a generic `roles.manage` Gate evaluate true for a non-owner, but the role/staff API and screens must still enforce actual `super_admin` membership. Future privileged endpoints should follow the same owner-identity guard, and permission mappings should be protected from out-of-band changes. P2B.5 will add administrative audit logging.

## Completion evidence to share

- New test counts / failures if any
- Full `php artisan test` result
- `vendor\bin\phpstan analyse` result
- TypeScript and build result
- Confirmation `/admin/roles` and `/admin/staff-users` work for owner and are forbidden to non-owner

**Project separation:** This applies only to Bablys Tours & Travels; not the user's news, e-commerce, corporate ERP, or other Laravel projects.
