<?php

namespace App\Http\Requests;

use App\Enums\MovementType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreInventoryMovementRequest extends FormRequest
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
            'data' => ['required', 'array:movement_type,item_id,location_id,source_location_id,destination_location_id,quantity,observed_quantity,note'],
            'data.movement_type' => ['required', 'string', Rule::enum(MovementType::class)],
            'data.item_id' => ['required', 'integer', 'min:1'],
            'data.location_id' => ['required_if:data.movement_type,restock,consumption,correction,disposal', 'prohibited_if:data.movement_type,transfer', 'integer', 'min:1'],
            'data.source_location_id' => ['required_if:data.movement_type,transfer', 'prohibited_unless:data.movement_type,transfer', 'integer', 'min:1'],
            'data.destination_location_id' => ['required_if:data.movement_type,transfer', 'prohibited_unless:data.movement_type,transfer', 'integer', 'min:1'],
            'data.quantity' => ['required_if:data.movement_type,restock,transfer,consumption,disposal', 'prohibited_if:data.movement_type,correction', 'integer', 'min:1', 'max:4294967295'],
            'data.observed_quantity' => ['required_if:data.movement_type,correction', 'prohibited_unless:data.movement_type,correction', 'integer', 'min:0', 'max:4294967295'],
            'data.note' => ['prohibited_unless:data.movement_type,correction'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $this->input('data');
            if (is_array($data) && ($data['movement_type'] ?? null) === MovementType::Transfer->value && ($data['source_location_id'] ?? null) === ($data['destination_location_id'] ?? null)) {
                $validator->errors()->add('data.destination_location_id', 'Source and destination locations must differ.');
            }
            if (is_array($data) && ($data['movement_type'] ?? null) === MovementType::Correction->value && isset($data['note']) && $data['note'] !== '') {
                $validator->errors()->add('data.note', 'Notes are not supported yet.');
            }
            if (is_array($data) && array_key_exists('note', $data) && ($data['movement_type'] ?? null) !== MovementType::Correction->value) {
                $validator->errors()->add('data.note', 'Notes are only accepted with correction movements.');
            }
        });
    }
}
