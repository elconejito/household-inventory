<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
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
            'data' => ['required', 'array:name,id,type'],
            'data.id' => ['prohibited'],
            'data.type' => ['prohibited'],
            'data.name' => [
                'required', 'string', 'max:255',
                Rule::unique('categories', 'name')->where('household_id', $this->householdId()),
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
