# P2B.5A — CMS Administrative Audit Database & Domain Contract

**Scope:** Bablys Tours & Travels only, Laravel 13 / Inertia React TSX. P2B.4C security QA accepted. This step introduces an append-only *application write API* and audit storage; **it does not yet log existing staff/role actions**. Those integrations belong to P2B.5B.

## Decision: schema and data semantics

The P2A blueprint already names `cms_audit_events`. The P2B.5A migration adds exactly one table:

| Column | Type | Note |
|---|---|---|
| `id` | UUID PK | Opaque event ID; separate from subject ID |
| `actor_id` | unsigned bigint nullable | User who performed the action; intentionally no delete-cascade foreign key |
| `action` | varchar(100) | Domain event key such as `staff.roles_changed` |
| `subject_type` | varchar(80) | Safe alias, e.g. `staff_user` or `cms_role`, not raw class name |
| `subject_id` | varchar(64) nullable | Supports integer IDs, UUIDs and future object types |
| `metadata_json` | JSON | Caller-curated metadata, recursively redacted, max 16 KiB |
| `ip_address` | varchar(45) nullable | Normalized Laravel IP where available; configure trusted proxies |
| `user_agent` | varchar(255) nullable | Length-bounded, credentials-in-header-like strings suppressed |
| `occurred_at` | timestamp(6) | UTC time; no `updated_at` field |

Indexes: `(actor_id, occurred_at)`, `(action, occurred_at)`, and `(subject_type, subject_id, occurred_at)` for later audit viewer filters.

The `actor_id` deliberately has **no foreign key to users**. Deleting a user must not cascade into audit history or erase attribution IDs. The later audit viewer must resolve actor names only with a left join and a deleted-account fallback. Application privileges do not include audit update/delete endpoints. Full tamper-proof or immutable storage needs database permissions / off-site retention and is **not** claimed here.

## Write contract

```php
use App\Domain\Cms\Audit\CmsAuditRecorder;
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($actor, $staff): void {
    // Perform the authorized business mutation first.
    // ...

    app(CmsAuditRecorder::class)->record(
        action: 'staff.roles_changed',
        actor: $actor,
        subjectType: 'staff_user',
        subjectId: (int) $staff->getKey(),
        metadata: [
            'role_keys_before' => ['booking_agent'],
            'role_keys_after' => ['content_editor'],
        ],
    );
});
```

### Safety and privacy rules

- **DO NOT** pass `$request->all()`, `$user->toArray()`, user emails, complete model snapshots, personal messages, booking passenger details, payment data, passwords, reset links, or API credentials as metadata.
- Call only after authorization has succeeded, and record *actual persisted changes*, not an unauthenticated claim about the intended action.
- Keep audit insert in the **same DB transaction** as the business change. Do not queue to another connection for core security events. Do not swallow an audit failure: roll back the mutation if the audit insert fails.
- Use stable action vocabulary (`staff.created`, `staff.updated`, `staff.roles_changed`, `staff.suspended`, `staff.restored`, `staff.invited`, `role.created`, `role.updated`, `role.permissions_changed`, `role.deleted`). Do not record full password reset URLs or tokens.
- `CmsAuditRedactor` removes obvious sensitive key/value patterns as defense in depth. It is **not** a replacement for explicit safe metadata construction and cannot identify every unknown secret or personal identifier.
- IP/client information should be treated as personal data. Restrict audit-reader permissions, set a retention policy, and configure trusted proxies correctly before production deployment.
- There is intentionally no HTTP endpoint to edit or delete an audit event and no Eloquent model that exposes write methods.

## P2B.5 boundary

- **P2B.5A (this package):** migration, recorder, redactor, contract tests.
- **P2B.5B:** integrate authorized staff/roles operations using the existing transaction boundaries; add failure/revocation and no-secret regression tests; avoid double writes on retry.
- **P2B.5C:** protected React TSX read-only log viewer using `audit.view`, owner-identity checks, search, filter, pagination and detail views; no audit edit/delete routes.
- **P2B.5D:** adversarial tests, performance, retention/privacy decisions, and production monitoring guidance.

## Acceptance gate

1. Migration is additive; existing auth, dashboard, staff and roles flows are unchanged.
2. Recorder insert persists actor and subject IDs with safe metadata; credentials are redacted.
3. Transactions roll back audit alongside mutations; audit failures are not silently ignored.
4. No audit update/delete endpoints, seed data, middleware, providers, or role changes were added.
5. Focused tests, full test suite, PHPStan, TypeScript checks and production build pass locally on a disposable test DB.
