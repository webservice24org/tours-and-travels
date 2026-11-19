<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Cms\CmsRole;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Opt-in trait for the existing User model.
 * NOT automatically inserted into User.php at P2B.1.
 * P2B.2 will integrate it together with policies/authorization tests.
 */
trait HasCmsRoles
{
    /** @return BelongsToMany<CmsRole, $this> */
    public function cmsRoles(): BelongsToMany
    {
        return $this->belongsToMany(
            CmsRole::class,
            'cms_role_user',
            'user_id',
            'role_id'
        )->withTimestamps();
    }
}
