<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreItemRequest extends FormRequest
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
            'data' => ['required', 'array:name,counting_unit,description,category_ids,opening_stock,id,type'],
            'data.id' => ['prohibited'],
            'data.type' => ['prohibited'],
            'data.name' => [
                'required', 'string', 'max:255',
                Rule::unique('items', 'name')->where('household_id', $this->householdId()),
            ],
            'data.opening_stock' => ['sometimes', 'array:location_id,quantity', 'required_array_keys:location_id,quantity'],
            'data.opening_stock.location_id' => ['required_with:data.opening_stock', 'integer', 'min:1'],
            'data.opening_stock.quantity' => ['required_with:data.opening_stock', 'integer', 'min:0', 'max:4294967295'],
            'data.counting_unit' => ['required', 'string', 'max:255'],
            'data.description' => ['sometimes', 'nullable', 'string'],
            'data.category_ids' => ['sometimes', 'array'],
            'data.category_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('categories', 'id')->where(fn (Builder $query) => $query
                    ->where('household_id', $this->householdId())
                    ->whereNull('deleted_at')),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = $this->input('data');

        if (is_array($data) && is_string($data['name'] ?? null)) {
            $data['name'] = Str::squish($data['name']);
            $this->merge(['data' => $data]);
        }
    }

    private function householdId(): ?int
    {
        return $this->user()?->households()->value('households.id');
    }
}
