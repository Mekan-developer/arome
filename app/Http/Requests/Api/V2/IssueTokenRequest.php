<?php

namespace App\Http\Requests\Api\V2;

use App\Services\V2\Data\DeviceCredentials;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Вход с телефона. Открытым остаётся намеренно — это единственный вызов v2 без токена.
 */
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

    public function credentials(): DeviceCredentials
    {
        $validated = $this->validated();

        return new DeviceCredentials(
            login: $validated['login'],
            password: $validated['password'],
            device: $validated['device'],
        );
    }
}
