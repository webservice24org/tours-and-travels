<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class CmsRole extends Model
{
    protected $table = 'cms_roles';

    /** @var list<string> */
    protected $fillable = ['key', 'name', 'description', 'is_system'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /** @return BelongsToMany<CmsPermission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            CmsPermission::class,
            'cms_permission_role',
            'role_id',
            'permission_id'
        )->withTimestamps();
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'cms_role_user',
            'role_id',
            'user_id'
        )->withTimestamps();
    }
}
