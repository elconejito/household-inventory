<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreRegistrationRequest extends FormRequest
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
            'data' => ['required', 'array:name,email,password,password_confirmation,household_name'],
            'data.name' => ['required', 'string', 'max:255'],
            'data.email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'data.password' => ['required', 'string', 'min:8', 'confirmed'],
            'data.password_confirmation' => ['required', 'string'],
            'data.household_name' => ['required', 'string', 'max:255'],
        ];
    }
}
