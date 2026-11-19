<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Roles;

use App\Domain\Cms\Access\RbacCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SyncCmsCustomRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roles.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'permission_keys' => ['present', 'array', 'max:28'],
            'permission_keys.*' => ['required', 'string', 'distinct', Rule::in(array_keys(RbacCatalog::permissions()))],
        ];
    }
}
