<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreLocationRequest extends FormRequest
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
            'data' => ['required', 'array:name,description,parent_id,id,type'],
            'data.id' => ['prohibited'],
            'data.type' => ['prohibited'],
            'data.name' => [
                'required', 'string', 'max:255',
                $this->uniqueNameRule($this->input('data.parent_id')),
            ],
            'data.description' => ['sometimes', 'nullable', 'string'],
            'data.parent_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('locations', 'id')->where('household_id', $this->householdId())->whereNull('deleted_at'),
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

    private function uniqueNameRule(mixed $parentId): Unique
    {
        return Rule::unique('locations', 'name')
            ->where('household_id', $this->householdId())
            ->where('parent_id', is_numeric($parentId) ? (int) $parentId : null);
    }

    private function householdId(): ?int
    {
        return $this->user()?->households()->value('households.id');
    }
}
