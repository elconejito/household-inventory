<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryLevelRequest extends FormRequest
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
            'data' => ['required', 'array:item_id,location_id,alert_threshold'],
            'data.item_id' => ['required', 'integer', 'min:1'],
            'data.location_id' => ['required', 'integer', 'min:1'],
            'data.alert_threshold' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4294967295'],
        ];
    }
}
