<?php

namespace App\Http\Requests;

use App\Enums\InventoryAlertType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexInventoryAlertRequest extends FormRequest
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
            'filter.status' => ['sometimes', 'string', Rule::in(['active', 'resolved', 'all'])],
            'filter.item_id' => ['sometimes', 'integer', 'min:1'],
            'filter.alert_type' => ['sometimes', 'string', Rule::enum(InventoryAlertType::class)],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50, 100])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
