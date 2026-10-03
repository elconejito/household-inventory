<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateItemImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'data' => ['required', 'array', 'min:1'],
            'data.caption' => ['sometimes', 'nullable', 'string', 'max:500'],
            'data.is_primary' => ['sometimes', 'accepted'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $data = $this->input('data', []);
            if (is_array($data)) {
                foreach (array_diff(array_keys($data), ['caption', 'is_primary']) as $unexpectedKey) {
                    $validator->errors()->add('data.'.$unexpectedKey, 'This field cannot be changed.');
                }
            }

            if (is_array($data) && array_key_exists('is_primary', $data) && $data['is_primary'] !== true) {
                $validator->errors()->add('data.is_primary', 'The primary photo can only be set to true.');
            }

            $body = array_merge($this->getInputSource()->all(), $this->allFiles());
            foreach (array_diff(array_keys($body), ['data']) as $unexpectedKey) {
                $validator->errors()->add($unexpectedKey, 'This field is not allowed.');
            }
        }];
    }
}
