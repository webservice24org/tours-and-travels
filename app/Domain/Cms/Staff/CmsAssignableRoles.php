<?php

declare(strict_types=1);

namespace App\Domain\Cms\Staff;

use App\Domain\Cms\Access\RbacCatalog;
use App\Domain\Cms\Roles\CmsCustomRoleManager;
use App\Models\Cms\CmsRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Single source of truth for owner-controlled staff role assignment. */
final class CmsAssignableRoles
{
    /** @return list<array{key:string,name:string,description:string}> */
    public function options(): array
    {
        return array_values(CmsRole::query()
            ->where('key', '!=', 'super_admin')
            ->orderByDesc('is_system')
            ->orderBy('id')
            ->get(['id', 'key', 'name', 'description', 'is_system'])
            ->filter(fn (CmsRole $role): bool => $this->trustedRole($role) && $this->hasNoUnsafeCustomGrants($role))
            ->map(static fn (CmsRole $role): array => [
                'key' => (string) $role->key,
                'name' => (string) $role->name,
                'description' => (string) ($role->description ?? ''),
            ])
            ->values()->all());
    }

    /**
     * Resolves role keys at write time, independently of UI or request validation.
     * @param list<string> $keys
     * @return list<int>
     */
    public function idsForKeys(array $keys): array
    {
        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            return [];
        }
        if (in_array('super_admin', $keys, true)) {
            $this->reject();
        }

        $roles = CmsRole::query()
            ->whereIn('key', $keys)
            ->get(['id', 'key', 'is_system']);
        if ($roles->count() !== count($keys)) {
            $this->reject();
        }
        foreach ($roles as $role) {
            if (! $this->trustedRole($role)) {
                $this->reject();
            }
        }

        // Custom roles cannot become a path to owner-only capabilities even if
        // their DB permission mappings were modified outside the management API.
        $customIds = $roles->filter(static fn (CmsRole $role): bool => ! (bool) $role->is_system)
            ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
        if ($customIds !== [] && DB::table('cms_permission_role')
            ->join('cms_permissions', 'cms_permissions.id', '=', 'cms_permission_role.permission_id')
            ->whereIn('cms_permission_role.role_id', $customIds)
            ->whereIn('cms_permissions.key', CmsCustomRoleManager::OWNER_ONLY_PERMISSIONS)
            ->exists()) {
            $this->reject();
        }

        return array_values($roles->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
    }

    private function trustedRole(CmsRole $role): bool
    {
        $key = (string) $role->key;
        if ($key === 'super_admin') {
            return false;
        }
        if ($role->is_system) {
            return array_key_exists($key, RbacCatalog::roles());
        }
        return (bool) preg_match('/\Acustom_[a-z][a-z0-9_]{2,48}\z/', $key);
    }


    private function hasNoUnsafeCustomGrants(CmsRole $role): bool
    {
        if ($role->is_system) {
            return true;
        }
        return ! DB::table('cms_permission_role')
            ->join('cms_permissions', 'cms_permissions.id', '=', 'cms_permission_role.permission_id')
            ->where('cms_permission_role.role_id', $role->getKey())
            ->whereIn('cms_permissions.key', CmsCustomRoleManager::OWNER_ONLY_PERMISSIONS)
            ->exists();
    }
 
    /** @return never */
    private function reject(): never
    {
        throw ValidationException::withMessages([
            'role_keys' => 'One or more roles are not available for safe staff assignment.',
        ]);
    }
}
