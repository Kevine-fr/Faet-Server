<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VendorRequest extends FormRequest
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
            'name_store' => 'nullable|string|max:255',
            'siret' => 'nullable|string|max:14',
            'ecommerce_platform' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'preferred_payment_method' => 'nullable|in:stripe,paypal',
        ];
    }
}
