<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProduitImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductVariantController extends Controller
{
    //

    /**
     * Ajouter une ou plusieurs variantes à un produit existant
     */
    public function storeVariant(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'variants' => 'required|array|min:1',
            'variants.*.price' => 'required|numeric|min:0',
            'variants.*.stock_quantity' => 'required|integer|min:0',
            'variants.*.size' => 'nullable|string|max:50',
            'variants.*.color' => 'nullable|string|max:50',
            'variants.*.weight' => 'nullable|numeric|min:0',
            'variants.*.attributes' => 'nullable|array',
        ]);

        try {
            $product = Product::findOrFail($validated['product_id']);
            // SKU de base = 4 premiers caractères
            $baseSku = strtoupper(substr($product->sku ?? Str::slug($product->name, '-'), 0, 4));

            $createdVariants = [];

            foreach ($validated['variants'] as $variantData) {

                // Vérifie si la combinaison size/color existe déjà
                $existsCombination = ProductVariant::where('product_id', $product->id)
                    ->where('size', $variantData['size'] ?? null)
                    ->where('color', $variantData['color'] ?? null)
                    ->exists();

                if ($existsCombination) {
                    return response()->json([
                        'message' => "La combinaison (size/color) existe déjà pour ce produit.",
                        'duplicate' => [
                            'product_id' => $product->id,
                            'size' => $variantData['size'],
                            'color' => $variantData['color'],
                        ],
                    ], 422);
                }

                // Génération d’un SKU unique
                do {
                    $randomSuffix = strtoupper(Str::random(7));

                    $parts = [
                        $baseSku,
                        isset($variantData['color']) ? strtoupper(substr($variantData['color'], 0, 3)) : null,
                        isset($variantData['size']) ? strtoupper(substr($variantData['size'], 0, 3)) : null,
                        isset($variantData['weight']) ? strtoupper(substr((string)(float)$variantData['weight'], 0, 3)) : null,
                        $randomSuffix,
                    ];

                    $generatedSku = implode('-', array_filter($parts));
                } while (ProductVariant::where('sku', $generatedSku)->exists());

                // Création de la variante
                $createdVariants[] = ProductVariant::create([
                    'product_id'     => $product->id,
                    'sku'            => $generatedSku,
                    'price'          => $variantData['price'],
                    'stock_quantity' => $variantData['stock_quantity'],
                    'size'           => $variantData['size'] ?? null,
                    'color'          => $variantData['color'] ?? null,
                    'weight'         => $variantData['weight'] ?? null,
                    'is_active'      => true,
                ]);
            }

            return response()->json([
                'message' => 'Variantes ajoutées avec succès au produit',
                'product_id' => $product->id,
                'variants' => $createdVariants,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erreur lors de l’ajout des variantes',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
     /**
     * ✅ Mettre à jour une variante de produit
     */
    // public function update(Request $request, int $id, ProduitImageService $imageService): JsonResponse
    // {
    //     $variant = ProductVariant::findOrFail($id);

    //     $validated = $request->validate([
    //         'external_id'    => 'nullable|string|max:255',
    //         'sku'            => 'nullable|string|max:255|unique:product_variants,sku,' . $variant->id,
    //         'price'          => 'nullable|numeric|min:0',
    //         'stock_quantity' => 'nullable|integer|min:0',
    //         'size'           => 'nullable|string|max:100',
    //         'color'          => 'nullable|string|max:100',
    //         'weight'         => 'nullable|numeric|min:0',
    //         'attributes'     => 'nullable|array',
    //         'is_active'      => 'boolean',
    //         'images.*'       => 'nullable|image|max:5120',
    //     ]);

    //     try {
    //         DB::beginTransaction();

    //         $variant->update($validated);

    //         // ✅ Si des images doivent être mises à jour
    //         if ($request->hasFile('images')) {
    //             $imageService->updateImagesForVariant($variant, $request->file('images'));
    //         }

    //         DB::commit();

    //         return response()->json([
    //             'message' => 'Variante mise à jour avec succès.',
    //             'data' => $variant->load('product')
    //         ]);

    //     } catch (\Throwable $e) {
    //         DB::rollBack();
    //         return response()->json([
    //             'error' => 'Erreur lors de la mise à jour de la variante.',
    //             'details' => $e->getMessage()
    //         ], 500);
    //     }
    // }
}