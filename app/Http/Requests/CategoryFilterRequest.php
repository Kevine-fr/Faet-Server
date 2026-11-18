<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CategoryFilterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'only_parents' => 'nullable|boolean',          // 1 = que les catégories principales
            'parent_id'    => 'nullable|exists:categories,id', // sous-catégories d'une catégorie
            'is_active'    => 'nullable|boolean',
            'per_page'     => 'nullable|integer|min:1|max:100',
        ];
    }
}