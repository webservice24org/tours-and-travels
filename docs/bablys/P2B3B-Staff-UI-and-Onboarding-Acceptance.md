# Bablys Tours & Travels — P2B.3B acceptance and security contract

Scope is **Bablys only**. Dependencies: P2A + P2B.1 + P2B.2 + P2B.3A already applied and tested. This milestone is an admin UI and invitation integration; it does **not** implement P2B.4 role-management CRUD or P2B.5 persistent audit logs.

## New functionality

| Endpoint | Protection | Responsibility |
| --- | --- | --- |
| `GET /admin/staff-users` | `auth`, `EnsureCmsStaff`, `EnsureCmsPermission:roles.manage`, service owner check | Render Inertia `admin/staff-users/index` with safe assignable role options |
| `POST /admin/internal/staff-users/{user}/setup-link` | Same plus `throttle:6,1` and service owner check | Send password-reset broker email to **unverified and CMS-enabled** non-super staff only |

Existing P2B.3A JSON endpoints remain the authority for search, pagination, profile changes, role replacement and CMS suspension. UI sends cookie-authenticated same-origin JSON requests with Laravel CSRF token. It never trusts a client-side role flag for security decisions.

## UI behaviors

- Responsive mobile cards and desktop table, status badges, filtering by name/email, role and status; 10/15/25/50 entries per page.
- Create pending staff with one or more non-super roles; never collect or generate a shared password in the browser.
- Edit user name/email, replace role membership (including deliberate empty set), suspend/restore CMS access, send password setup link to eligible pending staff.
- Client prevents editing the current super admin or another super admin, but backend validation is authoritative even if client logic is bypassed.
- Confirmation dialogs are required for role removal, CMS suspension/restoration and setup-link sending.
- Server validation errors are visible; React never attempts Inertia form submission to a JSON-only endpoint.
- Admin home shows the Staff users link only to a super admin who has `roles.manage`. The route still enforces security.

## Staff invitation and onboarding

- The action uses `Password::broker()->sendResetLink()` to dispatch Laravel's one-time, expiring, rate-limited password reset notification. Tokens never go to the admin browser or API response.
- It permits only a user with a non-super CMS role, **unverified email** and **non-suspended CMS staff access**.
- Recipient uses Fortify's standard reset-password link to choose their own password, then logs in and completes Fortify email verification. Resetting a password **does not** verify an email.
- Laravel `password.reset` and `verification.verify` routes must be enabled; no automatic verification or direct password assignment is implemented.
- Sender must configure SMTP or a proper mail transport in `.env`. With `MAIL_MAILER=log`, the message appears in `storage/logs/laravel.log` **for development only**; do not publish logs, and never print reset tokens to the admin screen.
- Verify the configured reset-token expiry and throttle via `config/auth.php`, mail delivery, queue processing, and email sender/domain before real staff onboarding.
- This is a reset-link-based onboarding workflow, not a branded separate invitation-token system. A branded invitation ledger and audit records may follow later.

## Protections and non-goals

- No automatic owner promotion; no staff deletion; no HTTP super-admin assignment.
- No migration and no change to User model, Fortify config, public homepage, login, registration or dashboard.
- No broad global sidebar change: adds a single permission-aware link to existing Bablys admin landing page.
- P2B.5 will add durable audit entries for profile/role/suspension/invitation actions. Until then, avoid asserting complete operational auditability.

## Local QA checklist

1. Dry-run installer, inspect patch preview, apply on a clean git commit and preserve installer backup.
2. `php artisan route:list --path=admin -v` includes the protected screen and setup-link route.
3. Test guest 302 to `/login`, customer 403, editor 403, super admin 200; direct JSON unauthorized mutations 403.
4. Create pending non-super staff via UI; test role selection, duplicate email validation, saved state, search and pagination.
5. Test `role_keys: []` removes all CMS access (the earlier P2B.3A fix should use `present|array`).
6. Test suspend/restore and immediate revocation of `/admin` access.
7. Configure email, click Send setup link and verify actual delivery; reset password; sign in; complete email verification; access CMS.
8. Ensure repeated setup-link requests are throttled and no tokens appear in JSON.
9. Run P2B.3B focused tests, previous P2B.3A/P2B.2 tests, full suite, `npm run types:check`, `npm run build`.
10. Inspect narrow-screen layout and keyboard access on your local browser.
