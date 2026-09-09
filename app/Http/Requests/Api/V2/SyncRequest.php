<?php

namespace App\Http\Requests\Api\V2;

use App\Services\V2\Data\DeviceHeartbeat;
use Illuminate\Foundation\Http\FormRequest;

class SyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'data_version' => ['nullable', 'integer', 'min:0'],
            'app_version' => ['nullable', 'string', 'max:16'],
        ];
    }

    public function heartbeat(): DeviceHeartbeat
    {
        $validated = $this->validated();

        return new DeviceHeartbeat(
            deviceName: $this->deviceName(),
            appVersion: $validated['app_version'] ?? null,
            sinceVersion: (int) ($validated['data_version'] ?? 0),
        );
    }

    /**
     * Телефон, с которого пришёл запрос: имя токена — это то самое имя устройства,
     * которое приложение прислало при входе, и по нему раздел различает аппараты.
     */
    private function deviceName(): string
    {
        $user = $this->user();

        return $user?->currentAccessToken()?->name ?? $user?->device ?? 'Неизвестное устройство';
    }
}
