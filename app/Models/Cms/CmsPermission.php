<?php

declare(strict_types=1);

namespace App\Models\Cms;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class CmsPermission extends Model
{
    protected $table = 'cms_permissions';

    /** @var list<string> */
    protected $fillable = [
        'key', 'module', 'action', 'scope', 'label', 'description',
    ];

    /** @return BelongsToMany<CmsRole, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            CmsRole::class,
            'cms_permission_role',
            'permission_id',
            'role_id'
        )->withTimestamps();
    }
}
