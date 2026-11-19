<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Domain\Cms\Access\CmsAccess;
use App\Models\Cms\CmsPermission;
use App\Models\User;

/** All permission mutations denied until a P2B.4 guarded service exists. */
final class CmsPermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return app(CmsAccess::class)->allows($user, 'roles.manage');
    }

    public function view(User $user, CmsPermission $permission): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CmsPermission $permission): bool
    {
        return false;
    }

    public function delete(User $user, CmsPermission $permission): bool
    {
        return false;
    }
}
