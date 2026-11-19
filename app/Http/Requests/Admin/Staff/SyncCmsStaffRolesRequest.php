<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SyncCmsStaffRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roles.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role_keys' => ['present', 'array', 'max:12'],
            'role_keys.*' => [
                'required', 'string', 'distinct',
                Rule::exists('cms_roles', 'key'),
            ],
        ];
    }
}
