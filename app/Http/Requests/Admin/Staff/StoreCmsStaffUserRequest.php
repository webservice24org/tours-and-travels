<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCmsStaffUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roles.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role_keys' => ['required', 'array', 'min:1', 'max:12'],
            'role_keys.*' => [
                'required', 'string', 'distinct',
                Rule::exists('cms_roles', 'key'),
            ],
        ];
    }
}
