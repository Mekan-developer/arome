<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreImportFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->managesCatalog() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx', 'max:20480'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Файл не выбран — перетащите прайс сюда или выберите на компьютере.',
            'file.mimes' => 'Ожидается .xlsx — тот же формат, что отдаёт кнопка «Экспорт» в товарах.',
            'file.max' => 'Файл больше 20 МБ. Разбейте прайс на части.',
        ];
    }
}
