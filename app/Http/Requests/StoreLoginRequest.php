<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->input('data');

        if (is_array($data) && isset($data['email']) && is_string($data['email'])) {
            $data['email'] = Str::lower(trim($data['email']));
            $this->merge(['data' => $data]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'data' => ['required', 'array:email,password'],
            'data.email' => ['required', 'string', 'email', 'max:255'],
            'data.password' => ['required', 'string'],
        ];
    }
}
