<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() || $this->user()?->isSuperadmin();
    }

    /**
     * The three rules shown as a live checklist beside the field: at least 8 characters,
     * a latin letter and a digit, no spaces.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'regex:/^\S+$/', 'regex:/[a-zA-Z]/', 'regex:/\d/'],
            'end_sessions' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.min' => 'Пароль не короче 8 символов.',
            'password.regex' => 'Пароль без пробелов, с латинской буквой и цифрой.',
        ];
    }
}
