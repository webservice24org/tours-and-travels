<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Staff;

use Illuminate\Foundation\Http\FormRequest;

final class SetCmsStaffAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roles.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
