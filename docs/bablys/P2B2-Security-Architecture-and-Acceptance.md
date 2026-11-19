# Bablys P2B.2 — Secure Admin Authorization & Acceptance

## Scope and explicit security decisions

- Uses the existing P2B.1 `cms_*` tables and seeded grants. **No migrations**.
- Does not edit `User.php`, `routes/web.php`, `bootstrap/app.php`, registration, homepage or dashboard.
- Dedicated provider in `bootstrap/providers.php` loads `routes/bablys_admin.php` and registers 28 real-time database-backed Laravel gates.
- Public registration never assigns roles. A logged-in user has no admin access without an explicitly assigned role with at least one currently granted admin-scoped permission.
- **Verified email required** for all staff access, even if general authentication permits unverified users.
- Role-name checks alone never grant individual capabilities. `super_admin` uses seeded permissions like every other role; it has **no bypass** of revoked grants.
- Unknown permission keys are denied. All changes to role and permission records remain blocked in policies until P2B.4.
- React shows allowed modules only, but every future controller/action MUST still enforce backend authorization.
- Only `/admin` is introduced; do not confuse this with full CMS dashboard or CRUD screens.
- Staff members are assigned the first super-admin role only through a deliberate server-side Artisan command, never by signup, first login, `.env` values or public request.

## Authorization workflow

```text
GET /admin
  -> web (CSRF/session)
  -> auth (unauthenticated -> login)
  -> EnsureCmsStaff
      -> verified-email check
      -> real-time DB role+permission join
      -> 403 if no valid admin permission
  -> BablysAdminHomeController
      -> Inertia admin/index with server-filtered capabilities
```

Future endpoints should use BOTH an admin group and their specific permission:

```php
use App\Http\Middleware\EnsureCmsPermission;
Route::post('/tours', [AdminTourController::class, 'store'])
    ->middleware(EnsureCmsPermission::class.':tours.create');
```

Inside service/controller, check again at the operation boundary:

```php
Gate::authorize('tours.publish');
```

Role and permission model policies exist but their write actions return **false** until the anti-escalation workflow in P2B.4 is implemented.

## One-time bootstrap

After P2B.1 seeding and confirming the intended email has been verified:

```powershell
php artisan bablys:bootstrap-super-admin owner@example.com --confirm-email=owner@example.com
```

In `APP_ENV=production`, add `--allow-production` **only** as part of a planned, approved server operation. The command locks the system role row, fails if *any* super-admin is already assigned, and never creates a user or grants roles through HTTP.

If a super-admin has already been created, the one-time command **cannot** be used to promote another. P2B.4 will implement a multi-party controlled workflow for subsequent changes.

## Verification matrix

| Scenario | Expected |
|---|---|
| Guest GET /admin | Redirect to login |
| Logged-in roleless registered user | 403 |
| Unverified staff user | 403 |
| Verified booking agent | Admin landing only; booking caps visible |
| Booking agent requesting reviews.moderate | Denied |
| Verified administrator | Relevant authorized capabilities |
| Super-admin losing a grant | That grant denied immediately |
| Unknown permission key | Denied |
| CMS role/permission writes through model policies | Denied until P2B.4 |
| Initial bootstrap with wrong confirmation | Failure, no changes |
| Initial bootstrap with unverified / missing account | Failure, no changes |
| Second bootstrap attempt | Failure, no extra super-admin |
| Public homepage, login, register, existing /dashboard | Unchanged |

## Risks & next milestones

- Never rely on UI hiding links for security; use Laravel authorization on EVERY future route, service, export, preview, upload, and update operation.
- Providers in `bootstrap/providers.php` are loaded while route caching. After production deployment, rebuild route cache with the updated provider list.
- P2B.3: secure staff listing and staff-role assignment UI. P2B.4: role editor with anti-escalation, protected owner role, dual-control for privileged changes. P2B.5: immutable/append-only audit log.
- A direct modification of the RBAC tables by a database administrator still bypasses app-layer policy; protect database credentials and operator access.
- Sitewide disabling of public registration is a **separate deployment decision**; existing registration remains intact for now, with zero admin permissions by default.
