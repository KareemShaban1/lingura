<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'native_language' => ['nullable', 'string', Rule::exists('languages', 'code')],
            'interface_language' => ['nullable', 'string', Rule::exists('languages', 'code')->where('is_interface_enabled', true)],
            'timezone' => ['nullable', 'timezone:all'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'accept_terms' => ['accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }
}
