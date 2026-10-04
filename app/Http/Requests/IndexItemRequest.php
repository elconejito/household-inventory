<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'filter.category_id' => [
                'sometimes',
                'integer',
                'min:1',
                Rule::exists('categories', 'id')->where(fn (Builder $query) => $query
                    ->where('household_id', $this->householdId())
                    ->whereNull('deleted_at')),
            ],
            'filter.location_id' => [
                'sometimes',
                'integer',
                'min:1',
                Rule::exists('locations', 'id')->where(fn (Builder $query) => $query
                    ->where('household_id', $this->householdId())
                    ->whereNull('deleted_at')),
            ],
            'filter.trashed' => ['sometimes', 'string', Rule::in(['with', 'only', 'without'])],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50, 100])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    private function householdId(): ?int
    {
        return $this->user()?->households()->value('households.id');
    }
}
