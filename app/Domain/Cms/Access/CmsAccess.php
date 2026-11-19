<?php

declare(strict_types=1);

namespace App\Domain\Cms\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Fail-closed, request-time database authorization for the Bablys CMS.
 * Never trust client props, session-cached permissions, or a role name alone.
 */
final class CmsAccess
{
    /** @return list<string> */
    public function roleKeysFor(User $user): array
    {
        return array_values(DB::table('cms_role_user')
            ->join('cms_roles', 'cms_roles.id', '=', 'cms_role_user.role_id')
            ->where('cms_role_user.user_id', $user->getKey())
            ->orderBy('cms_roles.key')
            ->pluck('cms_roles.key')
            ->map(static fn(mixed $key): string => (string) $key)
            ->values()
            ->all());
    }

    /** @return list<string> */
    public function permissionKeysFor(User $user): array
    {
        if (!$this->isEligibleForCmsAccess($user)) {
            return [];
        }

        $known = array_keys(RbacCatalog::permissions());

        return array_values(DB::table('cms_role_user')
            ->join('cms_roles', 'cms_roles.id', '=', 'cms_role_user.role_id')
            ->join('cms_permission_role', 'cms_permission_role.role_id', '=', 'cms_roles.id')
            ->join('cms_permissions', 'cms_permissions.id', '=', 'cms_permission_role.permission_id')
            ->where('cms_role_user.user_id', $user->getKey())
            ->where('cms_permissions.scope', 'admin')
            ->whereIn('cms_permissions.key', $known)
            ->distinct()
            ->orderBy('cms_permissions.key')
            ->pluck('cms_permissions.key')
            ->map(static fn(mixed $key): string => (string) $key)
            ->values()
            ->all());
    }

    public function allows(User $user, string $permission): bool
    {
        // A typo or unpublished new permission must never grant access.
        if (!array_key_exists($permission, RbacCatalog::permissions())) {
            return false;
        }

        if (!$this->isEligibleForCmsAccess($user)) {
            return false;
        }

        return DB::table('cms_role_user')
            ->join('cms_roles', 'cms_roles.id', '=', 'cms_role_user.role_id')
            ->join('cms_permission_role', 'cms_permission_role.role_id', '=', 'cms_roles.id')
            ->join('cms_permissions', 'cms_permissions.id', '=', 'cms_permission_role.permission_id')
            ->where('cms_role_user.user_id', $user->getKey())
            ->where('cms_permissions.key', $permission)
            ->where('cms_permissions.scope', 'admin')
            ->exists();
    }

    public function canEnterAdmin(User $user): bool
    {
        // A role without any grants (including a custom empty role) is not staff access.
        return $this->permissionKeysFor($user) !== [];
    }


    /**
     * Check the current database state before granting CMS access.
     *
     * A suspended or unverified user must have no CMS permissions.
     */
    private function isEligibleForCmsAccess(User $user): bool
    {
        return DB::table('users')
            ->where('id', $user->getKey())
            ->whereNotNull('email_verified_at')
            ->whereNull('cms_staff_disabled_at')
            ->exists();
    }

}
