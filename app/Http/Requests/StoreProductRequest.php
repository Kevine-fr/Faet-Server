<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
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
        'vendor_id' => 'required|exists:vendors,id',
        'category_id' => 'required|exists:categories,id',
        'name' => 'required|string|max:255',
        'description' => 'nullable|string',

        // 🧠 SKU requis uniquement si le produit n’a pas de variantes
        'sku' => [
            'nullable',
            'string',
            'max:50',
            'unique:products,sku',
            function ($attribute, $value, $fail) {
                $variants = request()->input('variants');
                if (empty($variants) && empty($value)) {
                    $fail("Le champ SKU est requis si le produit n'a pas de variantes.");
                }
            }
        ],

        'price' => 'nullable|numeric|min:0',
        'stock_quantity' => 'required|integer|min:0',

        // Variantes
        'variants' => 'nullable|array',
        'variants.*.size' => 'nullable|string|max:10',
        'variants.*.color' => 'nullable|string|max:50',
        'variants.*.weight' => 'nullable|numeric|min:0',
        'variants.*.stock_quantity' => 'required|integer|min:0',
        'variants.*.price' => 'nullable|numeric|min:0',
        'variants.*.sku' => 'nullable|string|max:50',

        // Images
        'images' => 'nullable|array',
        'images.*' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',        ];
    }

}