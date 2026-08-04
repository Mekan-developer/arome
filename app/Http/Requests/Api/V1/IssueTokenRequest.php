<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class IssueTokenRequest extends FormRequest
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
            'device' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * @return array{login: string, password: string, device: string}
     */
    public function credentials(): array
    {
        $validated = $this->validated();

        return [
            'login' => $validated['login'],
            'password' => $validated['password'],
            'device' => $validated['device'],
        ];
    }
}
