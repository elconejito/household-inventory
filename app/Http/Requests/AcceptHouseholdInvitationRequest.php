<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AcceptHouseholdInvitationRequest extends FormRequest
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
        $registrationRules = $this->user() === null ? 'required' : 'prohibited';

        return [
            'data' => ['required', 'array:token,email,name,password,password_confirmation'],
            'data.token' => ['required', 'string', 'min:32', 'max:128'],
            'data.email' => [$registrationRules, 'nullable', 'email', 'max:255'],
            'data.name' => [$registrationRules, 'nullable', 'string', 'max:255'],
            'data.password' => [$registrationRules, 'nullable', 'confirmed', Password::defaults()],
            'data.password_confirmation' => [$registrationRules, 'nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = $this->input('data');

        if (is_array($data) && is_string($data['email'] ?? null)) {
            $data['email'] = Str::lower(trim($data['email']));
            $this->merge(['data' => $data]);
        }
    }
}
