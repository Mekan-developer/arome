<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'login' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/', Rule::unique('users', 'login')],
            'role' => ['required', Rule::in(['admin', 'seller'])],
            'password' => ['required', 'string', 'min:8', 'regex:/^\S+$/', 'regex:/[a-zA-Z]/', 'regex:/\d/'],
            'points' => ['nullable', 'array'],
            'points.*' => ['integer', 'exists:points,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Не заполнено имя и фамилия — по ним сотрудника ищут в списке.',
            'login.required' => 'Не заполнен логин — под ним сотрудник входит в приложение.',
            'login.regex' => 'Логин — латинские строчные буквы, цифры и подчёркивание, без пробелов.',
            'login.unique' => 'Логин '.$this->input('login').' уже занят другим сотрудником.',
            'password.min' => 'Пароль не короче 8 символов.',
            'password.regex' => 'Пароль без пробелов, с латинской буквой и цифрой.',
        ];
    }

    /**
     * @return array{name: string, login: string, role: string, password: string, points: list<int>}
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'login' => $validated['login'],
            'role' => $validated['role'],
            'password' => $validated['password'],
            'points' => array_map('intval', $validated['points'] ?? []),
        ];
    }
}
