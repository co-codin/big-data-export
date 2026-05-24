<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the "regenerate report" form on the control page.
 * Category id must be a positive integer; everything else gets a 422.
 */
class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // no auth gate yet — see README "Operational gaps"
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Введите идентификатор категории.',
            'category_id.integer' => 'Идентификатор категории должен быть целым числом.',
            'category_id.min' => 'Идентификатор категории должен быть положительным.',
        ];
    }

    public function categoryId(): int
    {
        return (int) $this->validated('category_id');
    }
}
