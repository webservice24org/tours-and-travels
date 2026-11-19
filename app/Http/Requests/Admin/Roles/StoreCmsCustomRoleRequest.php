<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Roles;

use App\Domain\Cms\Access\RbacCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCmsCustomRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roles.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'key' => [
                'required', 'string', 'max:60',
                'regex:/\Acustom_[a-z][a-z0-9_]{2,48}\z/',
                Rule::unique('cms_roles', 'key'),
            ],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'permission_keys' => ['present', 'array', 'max:28'],
            'is_system' => ['prohibited'],
            'permission_keys.*' => ['required', 'string', 'distinct', Rule::in(array_keys(RbacCatalog::permissions()))],
        ];
    }
}
