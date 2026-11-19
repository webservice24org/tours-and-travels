# Bablys P2B.3A — Admin Staff User Management Backend

**Dependency:** P2A + P2B.1 + P2B.2 tested. Not shared with other projects.

## Backend contract

All routes live in the existing `web` route group, protected by `auth`,
`EnsureCmsStaff`, `EnsureCmsPermission:roles.manage`, and a throttle.

| Method | Endpoint | Action |
| --- | --- | --- |
| GET | `/admin/internal/staff-users` | Search/filter/paginate all accounts; safe fields only |
| POST | `/admin/internal/staff-users` | Create a pending staff account with initial non-super role(s) |
| GET | `/admin/internal/staff-users/{user}` | View safe fields and role names |
| PUT | `/admin/internal/staff-users/{user}` | Update name/email, reverify changed email |
| PUT | `/admin/internal/staff-users/{user}/roles` | Replace assigned non-super roles; [] demotes to customer |
| PATCH | `/admin/internal/staff-users/{user}/access` | Suspend or restore CMS staff permissions only |

JSON requests use session cookies and CSRF; do not convert these to publicly
accessible `api.php` routes. No user passwords, verification tokens, remember
tokens, or private PII fields are serialized. Page size max 50.

## Access model

P2A already defines `roles.manage`; the seed grants it only to the
`super_admin` role. P2B.3A requires **both** this real-time permission **and**
actual membership in the `super_admin` role to mutate staff. No `users.manage`
permission is added in this milestone. Users created via ordinary registration
receive no CMS roles.

- HTTP may **never** assign the `super_admin` role (only the controlled console
  bootstrap from P2B.2 is presently capable of assigning that role).
- Existing Super Administrators, including the acting account, may not be
  edited, demoted or disabled through these endpoints.
- CMS suspension is a nullable `users.cms_staff_disabled_at` timestamp.
  `CmsAccess` checks this alongside `email_verified_at` on every authorization
  call. Customers can still use their non-CMS account.
- A changed email becomes unverified; admin access is denied until verified.
- Role operations use a transaction and row locks for changes to user/roles.
- Role catalog is read from P2A/P2B.1; no generic mass-assignment of roles,
  user IDs, password, or verification status is accepted.

## Pending staff onboarding — deliberately NOT finished

P2B.3A creates pending accounts with a cryptographically random, unknown
password and unverified email. No password is returned or sent. Such accounts
cannot sign in until **P2B.3B** adds verified email invitations and password
setup using Laravel's password-reset broker. There is intentionally no mail
emission during account creation; SMTP must be configured/tested first.

Do not preverify staff accounts or distribute a known shared password to
bypass the pending state. Do not deploy staff creation publicly until the
invitation/setup UX is completed and verified.

## Installation and migration

1. Back up the Bablys database and version-control the current project.
2. Run the dry-run PowerShell installer, then apply if the preflight matches.
3. Run `php artisan migrate --pretend` before `php artisan migrate`.
4. `php artisan route:clear` then verify the `admin/internal/staff-users` route list.
5. Use a disposable test database for any `RefreshDatabase` tests.
6. Run targeted tests, full regression, static typecheck, frontend build.

The installer patches exactly TWO existing Bablys files:
`app/Domain/Cms/Access/CmsAccess.php` (deny suspended staff) and
`routes/bablys_admin.php` (include protected JSON routes). It backs up both.
Other additions are new files only. No change to login, Fortify, public pages,
admin landing page, or other projects.

## Test acceptance

- Guest/customer/non-privileged staff cannot read or modify staff accounts.
- Explicit owner can paginate, filter and edit, using only safe serialized fields.
- New accounts are unverified and cannot access CMS; injected password/verified
  state fields are ignored.
- `super_admin` cannot be assigned or modified via the HTTP staff API.
- Suspension/unsuspension changes access without requiring logout/relogin.
- Demotion strips CMS access; email updates require reverification.
- Wrong/duplicate roles fail validation with no partial changes.
- Previous P2B.1/P2B.2 tests, auth/home/dashboard and TSX build remain green.

## What's next

P2B.3B: responsive Inertia/React TSX admin list/create/edit/role screens,
secure invitation/password setup, UI guardrails. P2B.4: broader role and
permission administration. P2B.5: persisted audit trail for sensitive changes.
