<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Domain\Cms\Access\CmsAccess;
use App\Models\Cms\CmsRole;
use App\Models\User;

/**
 * Read access now; dangerous role writes explicitly denied until P2B.4.
 * P2B.4 must additionally enforce anti-escalation and protected system roles.
 */
final class CmsRolePolicy
{
    public function viewAny(User $user): bool
    {
        return app(CmsAccess::class)->allows($user, 'roles.manage');
    }

    public function view(User $user, CmsRole $role): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CmsRole $role): bool
    {
        return false;
    }

    public function delete(User $user, CmsRole $role): bool
    {
        return false;
    }
}
