<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
            'payment_method' => 'nullable|string|in:paypal,cb,stripe',
            
            // ✅ Support du format "orders" groupés
            'orders' => 'nullable|array',
            'orders.*.vendor_id' => 'required_with:orders|integer|exists:vendors,id',
            'orders.*.payment_method' => 'nullable|string|in:paypal,cb,stripe',
            'orders.*.promo_code_id' => 'nullable|integer|exists:promo_codes,id', // ✅ AJOUTÉ
            'orders.*.promo_code' => 'nullable|string', // ✅ AJOUTÉ
            'orders.*.items' => 'required_with:orders|array|min:1',
            'orders.*.items.*.product_id' => 'required|integer|exists:products,id',
            'orders.*.items.*.product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'orders.*.items.*.quantity' => 'required|integer|min:1',
            'orders.*.items.*.unit_price' => 'nullable|numeric|min:0', // ✅ AJOUTÉ
            
            // ✅ Support du format "items" plats (fallback)
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|integer|exists:products,id',
            'items.*.product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'items.*.quantity' => 'required_with:items|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0', // ✅ AJOUTÉ
        ];
    }

    /**
     * Messages d'erreur personnalisés
     */
    public function messages(): array
    {
        return [
            'orders.*.vendor_id.required_with' => 'Le vendeur est requis pour chaque commande.',
            'orders.*.vendor_id.exists' => 'Le vendeur spécifié n\'existe pas.',
            'orders.*.items.required_with' => 'Chaque commande doit contenir au moins un article.',
            'orders.*.items.min' => 'Chaque commande doit contenir au moins un article.',
            'orders.*.items.*.product_id.required' => 'L\'ID du produit est requis.',
            'orders.*.items.*.product_id.exists' => 'Le produit spécifié n\'existe pas.',
            'orders.*.items.*.quantity.required' => 'La quantité est requise.',
            'orders.*.items.*.quantity.min' => 'La quantité doit être au moins 1.',
            'orders.*.promo_code_id.exists' => 'Le code promo spécifié n\'existe pas.',
            'items.*.product_id.required_with' => 'L\'ID du produit est requis.',
            'items.*.product_id.exists' => 'Le produit spécifié n\'existe pas.',
            'items.*.quantity.required_with' => 'La quantité est requise.',
            'items.*.quantity.min' => 'La quantité doit être au moins 1.',
        ];
    }

    /**
     * Préparer les données pour la validation
     * Nettoyer et normaliser les données avant validation
     */
    protected function prepareForValidation()
    {
        // Convertir les strings en bons types si nécessaire
        if ($this->has('orders')) {
            $orders = $this->input('orders');
            
            foreach ($orders as $key => $order) {
                // Normaliser vendor_id
                if (isset($order['vendor_id'])) {
                    $orders[$key]['vendor_id'] = (int) $order['vendor_id'];
                }
                
                // Normaliser promo_code_id
                if (isset($order['promo_code_id']) && !empty($order['promo_code_id'])) {
                    $orders[$key]['promo_code_id'] = (int) $order['promo_code_id'];
                }
                
                // Normaliser items
                if (isset($order['items']) && is_array($order['items'])) {
                    foreach ($order['items'] as $itemKey => $item) {
                        if (isset($item['product_id'])) {
                            $orders[$key]['items'][$itemKey]['product_id'] = (int) $item['product_id'];
                        }
                        if (isset($item['product_variant_id']) && !empty($item['product_variant_id'])) {
                            $orders[$key]['items'][$itemKey]['product_variant_id'] = (int) $item['product_variant_id'];
                        }
                        if (isset($item['quantity'])) {
                            $orders[$key]['items'][$itemKey]['quantity'] = (int) $item['quantity'];
                        }
                        // ✅ Préserver unit_price si fourni
                        if (isset($item['unit_price'])) {
                            $orders[$key]['items'][$itemKey]['unit_price'] = (float) $item['unit_price'];
                        }
                    }
                }
            }
            
            $this->merge(['orders' => $orders]);
        }
    }
}