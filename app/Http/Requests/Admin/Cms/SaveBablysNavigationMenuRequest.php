<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Cms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class SaveBablysNavigationMenuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('settings.manage');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:0'],
            'name' => ['required', 'string', 'min:2', 'max:100', 'not_regex:/[<>]/'],
            'items' => ['present', 'array', 'list', 'max:80'],
            'items.*' => ['required', 'array:id,label,kind,url,parent_id,new_tab'],
            'items.*.id' => ['required', 'uuid'],
            'items.*.label' => ['required', 'string', 'max:100'],
            'items.*.kind' => ['required', 'in:internal,external'],
            'items.*.url' => ['required', 'string', 'max:350'],
            'items.*.parent_id' => ['nullable', 'uuid'],
            'items.*.new_tab' => ['required', 'boolean'],
        ];
    }
}
