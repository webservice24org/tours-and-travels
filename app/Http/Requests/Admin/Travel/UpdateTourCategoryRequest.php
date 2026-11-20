<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Travel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class UpdateTourCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('taxonomy.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:180'],
            // Supplied slug must equal its existing normalized value. No redirects yet.
            'slug' => ['sometimes', 'required', 'string', 'max:160'],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('travel_tour_categories', 'id')->whereNull('deleted_at')],
            'summary' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'status' => ['sometimes', Rule::in(['draft', 'in_review', 'published', 'archived'])],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
