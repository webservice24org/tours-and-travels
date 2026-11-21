<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Cms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class CreateBablysNavigationMenuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('settings.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'menu_key' => ['required', 'string', 'min:3', 'max:48', 'regex:/\A[a-z][a-z0-9-]{2,47}\z/D', Rule::unique('bablys_navigation_menus', 'menu_key')],
            'name' => ['required', 'string', 'min:2', 'max:100', 'not_regex:/[<>]/'],
        ];
    }
}
