<?php

namespace App\Http\Requests;

use App\Enums\MovementType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexInventoryMovementRequest extends FormRequest
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
            'filter.movement_type' => ['sometimes', 'string', Rule::enum(MovementType::class)],
            'filter.recorded_by' => ['sometimes', 'integer', 'min:1'],
            'filter.recorded_from' => ['sometimes', 'date'],
            'filter.recorded_until' => ['sometimes', 'date'],
            'filter.from_location_id' => ['sometimes', 'integer', 'min:1'],
            'filter.to_location_id' => ['sometimes', 'integer', 'min:1'],
            'filter.location_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50, 100])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
