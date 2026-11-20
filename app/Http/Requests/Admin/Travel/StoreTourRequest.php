<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Travel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class StoreTourRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('tours.create');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:200'],
            'slug' => ['required', 'string', 'max:160'],
            'summary' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],
            'duration_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'duration_nights' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'status' => ['sometimes', Rule::in(['draft', 'in_review', 'scheduled', 'published', 'archived'])],
            'scheduled_for' => ['nullable', 'date'],
            'category_ids' => ['sometimes', 'array', 'max:30'],
            'category_ids.*' => ['required', 'integer', 'min:1', 'distinct', Rule::exists('travel_tour_categories', 'id')->whereNull('deleted_at')],
            'tag_names' => ['sometimes', 'array', 'list', 'max:20'],
            'tag_names.*' => ['required', 'string', 'max:80'],
            'destination_ids' => ['sometimes', 'array', 'max:30'],
            'destination_ids.*' => ['required', 'integer', 'min:1', 'distinct', Rule::exists('travel_destinations', 'id')->whereNull('deleted_at')],
        ];
    }
}
