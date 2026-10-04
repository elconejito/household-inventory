<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexInventoryLevelRequest extends FormRequest
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
            'filter.item_id' => ['sometimes', 'integer', 'min:1'],
            'filter.location_id' => ['sometimes', 'string', 'max:4096', 'regex:/^[1-9][0-9]*(,[1-9][0-9]*)*$/'],
            'filter.quantity' => ['sometimes', 'integer', 'min:0'],
            'filter.alert_status' => ['sometimes', 'string', Rule::in(['triggered', 'empty', 'low', 'okay', 'unmonitored'])],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50, 100])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
