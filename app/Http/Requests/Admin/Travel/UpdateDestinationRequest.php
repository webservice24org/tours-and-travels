<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Travel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class UpdateDestinationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country_code'))) {
            $this->merge(['country_code' => strtoupper(trim((string) $this->input('country_code')))]);
        }
    }

    public function authorize(): bool
    {
        return Gate::allows('taxonomy.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:180'],
            // Supplied slug must match the current value. Redirect history arrives later.
            'slug' => ['sometimes', 'required', 'string', 'max:160'],
            'country_code' => ['sometimes', 'required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/D'],
            'region_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'city_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'decimal:0,7'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'decimal:0,7'],
            'status' => ['sometimes', Rule::in(['draft', 'in_review', 'published', 'archived'])],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
