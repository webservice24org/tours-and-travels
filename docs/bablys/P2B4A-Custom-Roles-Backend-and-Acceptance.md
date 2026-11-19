# Bablys Tours & Travels — P2B.4A Custom Roles Backend Contract

## Scope and dependencies

- Existing Laravel 13 + Inertia React TSX application; P2A, P2B.1, P2B.2, P2B.3A and P2B.3B already installed and verified.
- **No migrations**: reuse `cms_roles`, `cms_permissions`, `cms_role_user`, `cms_permission_role`.
- **Backend-only**: P2B.4B will add React TSX roles editor and adapt the existing staff role-selector APIs to include custom roles.
- Do not change public site, `/dashboard`, `/admin`, registration, login, user verification or staff invitations.
- Permission *definitions* remain code-owned by `RbacCatalog` and cannot be created/renamed through HTTP. Authorized admins manage custom **roles** and the **assignments of predefined permissions** to them.

## Security rules

1. All endpoints require verified, enabled CMS access; a currently effective `roles.manage` grant; and explicit actual `super_admin` membership. Mere possession of `roles.manage` is insufficient.
2. The seven `is_system = true` seeded roles are immutable through these endpoints. The `super_admin` role is never edited, deleted or delegated over HTTP.
3. Custom keys use `custom_...` and cannot be renamed after creation. The client cannot set `is_system`.
4. Owner-only permissions (`roles.manage`, `settings.manage`, `audit.view`) may not be assigned to custom roles, even by the owner.
5. `permission_keys` must be a present array; `[]` intentionally clears all permissions and revokes authorization at the next database-backed permission check.
6. A role with any user assignments cannot be deleted, preventing silent access removal. Reassign staff first.
7. All mutations use transactions and lock the target role for update where relevant.
8. The existing `CmsRolePolicy::create/update/delete` remains deny-by-default for generic model writes. **These specifically scoped P2B.4A endpoints** are authorized independently by a strict owner-only domain guard. Do not reuse the generic policy for unrelated writes.
9. No audit history is persisted yet (planned P2B.5). Treat P2B.4A as a locally tested foundation, not a fully audited production administration workflow.

## JSON endpoints (owner only)

Base URL: `/admin/internal/cms-roles`

| Method | Path | Purpose |
|---|---|---|
| GET | `/` | Paginated roles; `search`, `type=all\|system\|custom`, `per_page=1..50` |
| GET | `/catalog` | 28 predefined permissions with `assignable` flags |
| GET | `/{role}` | Detail including `permission_keys` and `user_count` |
| POST | `/` | Create `custom_...` role with name, description, `permission_keys` |
| PUT | `/{role}` | Edit custom role name/description (immutable key) |
| PUT | `/{role}/permissions` | Replace custom role permission keys; `[]` valid |
| DELETE | `/{role}` | Delete unassigned custom role, HTTP 204 |

### Example create request

```json
{
  "key": "custom_tour_operator",
  "name": "Tour Operator",
  "description": "Coordinates tour content and bookings",
  "permission_keys": ["tours.view", "tours.edit", "bookings.view"]
}
```

### Example role response

```json
{
  "data": {
    "id": 8,
    "key": "custom_tour_operator",
    "name": "Tour Operator",
    "description": "Coordinates tour content and bookings",
    "is_system": false,
    "user_count": 0,
    "permission_keys": ["bookings.view", "tours.edit", "tours.view"],
    "can_edit": true
  }
}
```

`id` is illustrative, not a fixed expected value.

## Acceptance criteria

- [ ] Guests and normal customers denied; non-owner staff denied even with an accidentally granted `roles.manage` permission.
- [ ] Owner can list/create/update/sync/delete custom roles.
- [ ] System roles cannot be modified or deleted.
- [ ] Unknown, repeated and owner-only permission keys rejected.
- [ ] `[]` permission sync immediately revokes live grants without relogin.
- [ ] Role cannot be deleted if any user is assigned.
- [ ] No changes to existing `CmsRolePolicy`, `CmsAccess`, staff pages or auth routes.
- [ ] Old full regression suite green; TypeScript check and build green.

## Intentional limit until P2B.4B

Existing P2B.3A `CmsStaffUserManager::roleIds()` and P2B.3B Staff UI currently expose only the original six non-super-admin seeded roles. The new backend can create custom roles and associate users in domain code, but the **staff assignment API and TSX forms are not expanded by this package**. P2B.4B will update those carefully with separate tests. Do not add user assignments directly through SQL in production.
