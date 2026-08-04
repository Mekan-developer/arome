<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->target());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = $this->target();

        return [
            'name' => ['required', 'string', 'max:255'],
            'login' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/', Rule::unique('users', 'login')->ignore($target->id)],
            // Корневой администратор остаётся администратором: понизив себя, он
            // закрыл бы раздел «Пользователи» для всех сразу.
            'role' => ['required', Rule::in($target->isRootAdmin() ? ['admin'] : ['admin', 'seller'])],
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
            'role.in' => 'Роль главного администратора менять нельзя.',
        ];
    }

    private function target(): User
    {
        return $this->route('user');
    }

    /**
     * @return array{name: string, login: string, role: string, points: list<int>}
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'login' => $validated['login'],
            'role' => $validated['role'],
            'points' => array_map('intval', $validated['points'] ?? []),
        ];
    }
}
