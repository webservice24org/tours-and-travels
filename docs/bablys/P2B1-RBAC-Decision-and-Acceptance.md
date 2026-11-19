# Bablys — P2B.1 RBAC Database Foundation

**Status:** Ready for local installation / acceptance; not yet run in the user's environment.
**Owner:** Bablys Tours & Travels project exclusively.

## Architecture decision

- Choose **native Laravel Eloquent + four prefixed ACL tables**, as proposed in P2A. Do not install a second ACL system alongside it.
- Create `cms_roles`, `cms_permissions`, `cms_role_user`, `cms_permission_role` in one additive migration.
- Default roles: `super_admin`, `administrator`, `content_editor`, `designer`, `review_moderator`, `booking_agent`, `seo_editor`.
- Define 28 permissions from the *exact P2A matrix*. `super_admin` initially receives all 28 permission mappings; `administrator` receives 25. No automatic bypass or privilege escalation logic exists in this milestone.
- Seed catalog and initial role/permission edges, **not user/role edges**. Nobody becomes an admin due to this package.
- Seeder is transactional and safe to rerun. It does not modify preexisting role names or restore grants removed from an existing role; default mapping is initialized only when a default role is newly created.
- Role and permission keys are stable API identifiers; do not rename casually. Custom role CRUD and editing are added only after P2B.2 authorization.

## Security boundaries

1. Existing `users`, auth registration, sessions, dashboard, home route, and Inertia components are untouched.
2. **No authorization middleware, policy, super-admin bypass, or admin endpoint is introduced here.** Existing `/dashboard` stays the starter-kit dashboard. Do not treat it as a protected CMS administration area.
3. Do not use self-registration to assign any staff role. Initial `super_admin` assignment is a separate, carefully verified P2B.2 task.
4. Role-to-user assignments use existing user IDs via `cms_role_user`. User deletion cascades only the pivot; deleting roles/permissions with active pivot links is restricted.
5. Use controlled administrative services for editing `key`, `is_system`, and permission grants in P2B.4. Direct `CmsRole::create($request->all())` is prohibited.
6. Permission keys are namespaced by module, all seed scopes are `admin` (scope is metadata until enforced by later policies).
7. A real authorization boundary will be covered in P2B.2 with policies, gates, request validation, middleware, tests and an audited initial privilege-grant workflow.

## Installation acceptance checklist

- [ ] Back up local MySQL database and check clean working tree before migration.
- [ ] Run PowerShell installer with `-WhatIf`, then apply after verifying there are no file conflicts.
- [ ] Confirm DB connection points to the **Bablys local development database** (`php artisan about --only=environment`).
- [ ] `php artisan migrate:status`, then review `php artisan migrate --pretend`, then `php artisan migrate`.
- [ ] `php artisan db:seed --class=BablysCmsRbacSeeder`.
- [ ] `php artisan test --filter=RbacCatalogTest`.
- [ ] `php artisan test --filter=RbacFoundationTest` against **dedicated test DB**; never use live DB for `RefreshDatabase`.
- [ ] `php artisan test --filter=PublicHomeTest` and full test suite.
- [ ] `npm run build` and `npm run types:check` if those scripts exist.
- [ ] Verify existing login, registration, dashboard, public home in browser.
- [ ] Confirm user-role pivot has zero rows until a future explicit staff assignment.

## Database rollback

A migration rollback **drops all four RBAC tables and stored grants**. This is intentionally not automated by the installer. Before rollback take a DB backup and confirm there are no staff role assignments or role changes. The rollback does not remove any users, but does destroy all RBAC catalog and mapping data.

## Next milestone

**P2B.2** — User-side role relationship integration, route authorization, Gates/Policies, admin middleware, cache invalidation when grants change, explicit bootstrap of first super admin through restricted CLI workflow, adversarial regression tests. Only after P2B.2 should protected `/admin` routes be introduced.
