<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array{login: string, password: string}
     */
    public function credentials(): array
    {
        $validated = $this->validated();

        return [
            'login' => $validated['login'],
            'password' => $validated['password'],
        ];
    }
}
