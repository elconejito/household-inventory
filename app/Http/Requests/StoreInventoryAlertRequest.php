<?php

namespace App\Http\Requests;

use App\Enums\InventoryAlertType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreInventoryAlertRequest extends FormRequest
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
            'data' => ['required', 'array:item_id,alert_type'],
            'data.item_id' => ['required', 'integer', 'min:1'],
            'data.alert_type' => ['required', 'string', Rule::enum(InventoryAlertType::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unknown = array_diff(array_keys($this->getInputSource()->all()), ['data']);
            if ($unknown !== []) {
                $validator->errors()->add('body', 'Unexpected request fields are not allowed.');
            }
        });
    }
}
