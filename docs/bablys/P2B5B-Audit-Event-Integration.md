# P2B.5B — Auditing staff and custom-role changes

This package is **Bablys-only**. It adds an audit writer, targeted source insertions in the two domain manager classes, and new integration tests. No migrations, providers, routes, seed changes or React files.

## Event catalog

| Event | Subject | Curated metadata |
|---|---|---|
| `staff.created` | `staff_user` | `role_keys_after`, `email_verified=false` |
| `staff.updated` | `staff_user` | `changed_fields`, `email_verification_cleared` |
| `staff.roles.changed` | `staff_user` | sorted `role_keys_before`, `role_keys_after` |
| `staff.suspended` | `staff_user` | `enabled_before`, `enabled_after` |
| `staff.restored` | `staff_user` | `enabled_before`, `enabled_after` |
| `role.created` | `cms_role` | `role_key`, sorted `permission_keys_after` |
| `role.updated` | `cms_role` | `role_key`, `changed_fields` |
| `role.permissions.changed` | `cms_role` | `role_key`, sorted `permission_keys_before`, `permission_keys_after` |
| `role.deleted` | `cms_role` | `role_key`, sorted `permission_keys_before` |

A role's human-readable name, description, staff name, staff email and full user attributes are **not** written. Passwords, password-reset tokens, invitation links, API credentials and raw input are never passed to the recorder. No-op profile/assignment/status/permission updates produce no event. Rejected requests produce no audit event.

## Atomicity and access

Events are emitted inside the same existing `DB::transaction` as mutations; when insertion fails, the entire mutation fails. Audit writes use the P2B.5A recorder and redactor. No general-purpose audit HTTP API is exposed here. P2B.5C is the owner-only audit viewer, followed by QA and retention policy work.

## Installer design

`patches.json` holds 9 **exact, minimal** before/after code replacements, not entire class overrides. The PowerShell installer normalizes newline differences while retaining local line endings, verifies every expected anchor occurs once, checks new-file conflicts and backs up both managers before editing. All checks occur before any source is changed. It stops rather than guessing if the code differs from the expected structure.

**Known limitation:** This milestone audits the specified staff and custom-role domain manager methods. It does not yet cover login events, invitation delivery, system role seeding, direct SQL edits or other future CMS modules. It does not implement database-level append-only enforcement or data retention; those require separate infrastructure controls.
