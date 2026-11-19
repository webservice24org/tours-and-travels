# P2B.4B — Acceptance and security checklist

## User experience
- [ ] Super Administrator can browse roles and see a system/custom filter and pagination.
- [ ] Super Administrator can create a custom role with non-owner permissions.
- [ ] Custom role metadata can be updated, while `key` and `is_system` remain non-writable.
- [ ] Grouped checkboxes never enable owner-only permission grants.
- [ ] System roles are clearly marked read-only.
- [ ] Role deletion requires confirmation and is blocked for assigned roles.
- [ ] Staff Users screen lists new custom roles automatically (no hardcoded TSX role list).
- [ ] Staff creation, sync, empty-role revoke, suspension and invitation are not regressed.

## Authorization
- [ ] Guests redirected from `/admin/roles`, customers/staff forbidden.
- [ ] A `roles.manage` permission on a non-owner does not bypass owner identity checks.
- [ ] Staff API cannot attach `super_admin` role or custom roles containing owner-only capabilities.
- [ ] User role changes are transaction-bound; permissions do not rely on stale client state.
- [ ] Unverified/suspended users cannot access CMS despite role assignments.
- [ ] System role catalog and P2B.4A permission constraints remain immutable.

## Quality gates
- [ ] `php artisan test --filter=CmsRolesUiAndStaffAssignmentTest`
- [ ] `php artisan test --filter=CmsCustomRoleManagementTest`
- [ ] `php artisan test --filter=AdminStaffUserManagementTest`
- [ ] `php artisan test` (dedicated test database)
- [ ] `vendor\\bin\\phpstan analyse`
- [ ] `npm run types:check`
- [ ] `npm run build`

**No migrations. No change to public homepage or authentication. Audit logging is P2B.5.**
