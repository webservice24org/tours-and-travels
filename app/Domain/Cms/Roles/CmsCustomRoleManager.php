<?php

declare(strict_types=1);

namespace App\Domain\Cms\Roles;

use App\Domain\Cms\Access\CmsAccess;
use App\Domain\Cms\Access\RbacCatalog;
use App\Models\Cms\CmsPermission;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P2B.4A: owner-only custom role mutations against the existing P2B.1 schema.
 * Default/system role definitions and permission keys are code-owned.
 */
final class CmsCustomRoleManager
{
    /** @var list<string> These grants must never be delegated to a custom role. */
    public const OWNER_ONLY_PERMISSIONS = [
        'roles.manage',
        'settings.manage',
        'audit.view',
    ];

    public function __construct(private readonly CmsAccess $access) {}

    public function assertOwner(User $actor): void
    {
        // Do not equate a roles.manage grant with super-admin identity.
        abort_unless($this->access->allows($actor, 'roles.manage'), 403);
        abort_unless(
            DB::table('cms_role_user')
                ->join('cms_roles', 'cms_roles.id', '=', 'cms_role_user.role_id')
                ->where('cms_role_user.user_id', $actor->getKey())
                ->where('cms_roles.key', 'super_admin')
                ->where('cms_roles.is_system', true)
                ->exists(),
            403
        );
    }

    /** @return list<string> */
    public function assignablePermissionKeys(): array
    {
        return array_values(array_diff(
            array_keys(RbacCatalog::permissions()),
            self::OWNER_ONLY_PERMISSIONS
        ));
    }

    /**
     * @param array{key:string,name:string,description?:?string,permission_keys:list<string>} $data
     */
    public function create(User $actor, array $data): CmsRole
    {
        return DB::transaction(function () use ($actor, $data): CmsRole {
            $this->assertOwner($actor);
            $ids = $this->permissionIds($data['permission_keys']);

            // Only custom roles may be created via HTTP; system role status is never client controlled.
            $role = CmsRole::query()->create([
                'key' => $data['key'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_system' => false,
            ]);
            $role->permissions()->sync($ids);
            $audit = app(\App\Domain\Cms\Audit\CmsMutationAudit::class);
            $audit->record('role.created', $actor, 'cms_role', $role, [
                'role_key' => (string) $role->key,
                'permission_keys_after' => $audit->permissionKeysForRole($role),
            ]);

            return $role->load('permissions')->loadCount('users');
        }, 3);
    }

    /** @param array{name:string,description?:?string} $data */
    public function update(User $actor, CmsRole $role, array $data): CmsRole
    {
        return DB::transaction(function () use ($actor, $role, $data): CmsRole {
            $this->assertOwner($actor);
            $locked = $this->lockMutableRole($role);
            $changedFields = [];
            if ($locked->name !== $data['name']) {
                $changedFields[] = 'name';
            }
            if ($locked->description !== ($data['description'] ?? null)) {
                $changedFields[] = 'description';
            }
            $locked->name = $data['name'];
            $locked->description = $data['description'] ?? null;
            $locked->save();
            if ($changedFields !== []) {
                app(\App\Domain\Cms\Audit\CmsMutationAudit::class)->record(
                    'role.updated', $actor, 'cms_role', $locked,
                    ['role_key' => (string) $locked->key, 'changed_fields' => $changedFields],
                );
            }
            return $locked->load('permissions')->loadCount('users');
        }, 3);
    }

    /** @param list<string> $permissionKeys */
    public function syncPermissions(User $actor, CmsRole $role, array $permissionKeys): CmsRole
    {
        return DB::transaction(function () use ($actor, $role, $permissionKeys): CmsRole {
            $this->assertOwner($actor);
            $locked = $this->lockMutableRole($role);
            $ids = $this->permissionIds($permissionKeys);
            $audit = app(\App\Domain\Cms\Audit\CmsMutationAudit::class);
            $before = $audit->permissionKeysForRole($locked);
            $after = $audit->sortedKeys($permissionKeys);
            if ($before !== $after) {
                // No permission cache: CmsAccess reads from DB at each check.
                $locked->permissions()->sync($ids);
                $audit->record('role.permissions.changed', $actor, 'cms_role', $locked, [
                    'role_key' => (string) $locked->key,
                    'permission_keys_before' => $before,
                    'permission_keys_after' => $after,
                ]);
            }
            return $locked->load('permissions')->loadCount('users');
        }, 3);
    }

    public function delete(User $actor, CmsRole $role): void
    {
        DB::transaction(function () use ($actor, $role): void {
            $this->assertOwner($actor);
            $locked = $this->lockMutableRole($role);
            // Never silently strip a role from staff; reassign them first.
            if ($locked->users()->exists()) {
                throw ValidationException::withMessages([
                    'role' => 'This role is assigned to users. Reassign those users before deleting it.',
                ]);
            }
            $audit = app(\App\Domain\Cms\Audit\CmsMutationAudit::class);
            $permissionKeysBefore = $audit->permissionKeysForRole($locked);
            $locked->permissions()->detach();
            $locked->delete();
            $audit->record('role.deleted', $actor, 'cms_role', $locked, [
                'role_key' => (string) $locked->key,
                'permission_keys_before' => $permissionKeysBefore,
            ]);
        }, 3);
    }

    private function lockMutableRole(CmsRole $role): CmsRole
    {
        $locked = CmsRole::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->is_system || array_key_exists((string) $locked->key, RbacCatalog::roles())) {
            throw ValidationException::withMessages([
                'role' => 'System roles are immutable. Create a custom role instead.',
            ]);
        }
        return $locked;
    }

    /**
     * @param list<string> $permissionKeys
     * @return list<int>
     */
    private function permissionIds(array $permissionKeys): array
    {
        $allowedKeys = $this->assignablePermissionKeys();
        if (count($permissionKeys) !== count(array_unique($permissionKeys)) ||
            array_diff($permissionKeys, $allowedKeys) !== []) {
            throw ValidationException::withMessages([
                'permission_keys' => 'Only distinct, approved, non-owner permissions may be assigned.',
            ]);
        }
        if ($permissionKeys === []) {
            return [];
        }
        $permissions = CmsPermission::query()
            ->where('scope', 'admin')
            ->whereIn('key', $permissionKeys)
            ->get(['id', 'key']);

        if ($permissions->count() !== count($permissionKeys)) {
            throw ValidationException::withMessages([
                'permission_keys' => 'One or more registered CMS permissions are missing.',
            ]);
        }
        return array_values($permissions->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all());
    }
}
