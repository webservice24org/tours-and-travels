<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Roles;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCmsCustomRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roles.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            // Key and is_system are intentionally not writable.
            'key' => ['prohibited'],
            'is_system' => ['prohibited'],
        ];
    }
}
