<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductDetailResource;
use App\Http\Resources\ProductDetailVResource;
use App\Services\ProductService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Http\Requests\ProductFilterRequest;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\StoreProductRequest;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary as CloudinaryFacade;
use Illuminate\Support\Facades\Auth;
use Exception;

class ProductController extends Controller
{
    protected $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }
    /**
     * Afficher la liste des produits du vendeur
     */
    public function index(Request $request)
    {
        try {
            $user = JWTAuth::user();

            // Vérifier si l'utilisateur a un profil vendeur
            $vendor = $user->vendor;
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé - Profil vendeur requis'
                ], 403);
            }

            // Récupérer les paramètres de recherche et pagination
            $search = $request->get('search', '');
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 10);

            // Construire la requête de base - IMPORTANT : Filtrer UNIQUEMENT par vendor_id du vendeur connecté
            // SoftDeletes exclut automatiquement les produits supprimés
            $query = Product::where('vendor_id', $vendor->id)
                ->with(['category', 'images', 'variants', 'audiences']);

            // Ajouter la recherche si un terme est fourni
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                      ->orWhere('sku', 'LIKE', "%{$search}%")
                      ->orWhere('description', 'LIKE', "%{$search}%");
                });
            }

            // Appliquer la pagination
            $products = $query->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            // Traiter les produits pour calculer les prix
            $products->getCollection()->transform(function ($product) {
                // Calculer le prix minimum des variantes si le prix du produit est null
                if ($product->price === null) {
                    $minVariantPrice = $product->variants()
                        ->whereNotNull('price')
                        ->where('price', '>', 0)
                        ->min('price');

                    if ($minVariantPrice !== null) {
                        $product->price = $minVariantPrice;
                    }
                }

                // S'assurer que les audiences sont formatées correctement
                if ($product->audiences) {
                    $product->audiences = $product->audiences->map(function ($audience) {
                        return [
                            'id' => $audience->id,
                            'name' => $audience->name,
                        ];
                    });
                }

                return $product;
            });

            return response()->json([
                'success' => true,
                'data' => $products->items(),
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'from' => $products->firstItem(),
                    'to' => $products->lastItem(),
                    'has_more_pages' => $products->hasMorePages(),
                    'has_previous_pages' => $products->currentPage() > 1
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des produits: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des produits'
            ], 500);
        }
    }

    /**
     * Afficher un produit spécifique
     */
    public function show($id)
    {
        try {
            $user = JWTAuth::user();

            // Vérifier si l'utilisateur a un profil vendeur
            $vendor = $user->vendor;
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé - Profil vendeur requis'
                ], 403);
            }

            // SoftDeletes exclut automatiquement les produits supprimés
            // Utiliser findByHash pour accepter ID ou hash
            $product = Product::findByHash($id)
                ->where('vendor_id', $vendor->id)
                ->with(['category', 'images', 'variants'])
                ->first();

            if ($product) {
                // Calculer le prix minimum des variantes si le prix du produit est null
                if ($product->price === null) {
                    $minVariantPrice = $product->variants()
                        ->whereNotNull('price')
                        ->where('price', '>', 0)
                        ->min('price');

                    if ($minVariantPrice !== null) {
                        $product->price = $minVariantPrice;
                    }
                }
            }

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produit non trouvé'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $product
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération du produit: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du produit'
            ], 500);
        }
    }

    /**
     * Récupérer les images d'un produit
     */
    public function getProductImages($id)
    {
        try {
            $user = JWTAuth::user();

            // Vérifier si l'utilisateur a un profil vendeur
            $vendor = $user->vendor;
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé - Profil vendeur requis'
                ], 403);
            }

            // SoftDeletes exclut automatiquement les produits supprimés
            // Utiliser findByHash pour accepter ID ou hash
            $product = Product::findByHash($id)
                ->where('vendor_id', $vendor->id)
                ->with('images')
                ->first();

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produit non trouvé'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'images' => $product->images
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des images du produit: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des images du produit'
            ], 500);
        }
    }

    /**
     * Supprimer un produit
     */
    public function destroy($id)
    {
        try {
            $user = JWTAuth::user();

            // Vérifier si l'utilisateur a un profil vendeur
            $vendor = $user->vendor;
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé - Profil vendeur requis'
                ], 403);
            }

            // SoftDeletes exclut automatiquement les produits supprimés
            // Utiliser findByHash pour accepter ID ou hash
            $product = Product::findByHash($id)
                ->where('vendor_id', $vendor->id)
                ->first();

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produit non trouvé'
                ], 404);
            }

            // Vérifier si le produit a des commandes actives
            $activeOrders = $product->orderItems()
                ->whereHas('order', function($query) {
                    $query->whereIn('status', ['in_preparation', 'to_be_collected']);
                })
                ->count();

            if ($activeOrders > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer ce produit car il a des commandes actives'
                ], 400);
            }

            // Soft delete avec SoftDeletes de Laravel
            $productName = $product->name;
            $product->delete();

            Log::info("Produit marqué comme supprimé par le vendeur {$vendor->id}: {$productName} (ID: {$id})");

            return response()->json([
                'success' => true,
                'message' => 'Produit supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression du produit: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du produit'
            ], 500);
        }
    }

    /**
     * Mettre à jour un produit
     */
    public function update(Request $request, $id)
    {
        try {
            $user = JWTAuth::user();

            // Vérifier si l'utilisateur a un profil vendeur
            $vendor = $user->vendor;
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé - Profil vendeur requis'
                ], 403);
            }

            // SoftDeletes exclut automatiquement les produits supprimés
            // Utiliser findByHash pour accepter ID ou hash
            $product = Product::findByHash($id)
                ->where('vendor_id', $vendor->id)
                ->first();

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produit non trouvé'
                ], 404);
            }

            $request->validate([
                'name' => 'sometimes|string|max:255',
                'description' => 'sometimes|string',
                'price' => 'sometimes|numeric|min:0',
                'stock' => 'sometimes|integer|min:0',
                'status' => 'sometimes|in:active,inactive,draft'
            ]);

            $product->update($request->only([
                'name', 'description', 'price', 'stock', 'status'
            ]));

            Log::info("Produit mis à jour par le vendeur {$vendor->id}: {$product->name} (ID: {$id})");

            return response()->json([
                'success' => true,
                'message' => 'Produit mis à jour avec succès',
                'data' => $product
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour du produit: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du produit'
            ], 500);
        }
    }

    /**
     * Mettre à jour la catégorie d'un produit
     */
    public function updateProductCategory(Request $request, $productId)
    {
        try {
            $user = JWTAuth::user();

            // Vérifier si l'utilisateur a un profil vendeur
            $vendor = $user->vendor;
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé - Profil vendeur requis'
                ], 403);
            }

            // Valider la requête
            $request->validate([
                'category_id' => 'nullable|integer|exists:categories,id'
            ]);

            // Trouver le produit et vérifier qu'il appartient au vendeur
            // SoftDeletes exclut automatiquement les produits supprimés
            $product = Product::where('id', $productId)
                ->where('vendor_id', $vendor->id)
                ->first();

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produit non trouvé'
                ], 404);
            }

            // Mettre à jour la catégorie (peut être null pour "Aucune catégorie")
            $oldCategoryId = $product->category_id;
            $newCategoryId = $request->has('category_id') && $request->category_id !== null ? $request->category_id : null;
            $product->update(['category_id' => $newCategoryId]);

            Log::info("Catégorie du produit {$product->name} (ID: {$productId}) mise à jour de {$oldCategoryId} vers {$newCategoryId} par le vendeur {$vendor->id}");

            return response()->json([
                'success' => true,
                'message' => 'Catégorie du produit mise à jour avec succès',
                'data' => [
                    'product_id' => $product->id,
                    'old_category_id' => $oldCategoryId,
                    'new_category_id' => $newCategoryId
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour de la catégorie du produit: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de la catégorie'
            ], 500);
        }
    }

    /**
     * Mettre à jour la catégorie de plusieurs produits
     */
    public function updateMultipleProductsCategory(Request $request)
    {
        try {
            $user = JWTAuth::user();

            // Vérifier si l'utilisateur a un profil vendeur
            $vendor = $user->vendor;
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé - Profil vendeur requis'
                ], 403);
            }

            // Valider la requête
            $request->validate([
                'product_ids' => 'required|array',
                'product_ids.*' => 'integer|exists:products,id',
                'category_id' => 'nullable|integer|exists:categories,id',
                'audience_id' => 'required|integer|exists:audiences,id'
            ]);

            // Utiliser le service pour mettre à jour les produits
            $newCategoryId = $request->has('category_id') && $request->category_id !== null ? $request->category_id : null;

            $result = $this->productService->updateMultipleProductsCategoryAndAudience(
                $request->product_ids,
                $newCategoryId,
                $request->audience_id,
                $vendor->id
            );

            // Message adapté selon si une catégorie a été mise à jour ou non
            $message = $request->has('category_id') && $request->category_id !== null
                ? "Catégorie et audience de {$result['updated_count']} produit(s) mise à jour avec succès"
                : "Audience de {$result['updated_count']} produit(s) mise à jour avec succès";

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour de la catégorie de plusieurs produits: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            Log::error('File: ' . $e->getFile() . ' Line: ' . $e->getLine());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour des catégories: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Afficher la liste des produits avec le nom de la catégorie (version admin)
     */
    public function indexAdmin(ProductFilterRequest $request)
    {
        try {
            $perPage = $request->input('per_page', 50);

            $products = $this->productService->getPaginated(
                $perPage,
                $request->validated()
            );

            // Transformation du prix si null → prix min des variantes
            $products->getCollection()->transform(function ($product) {
                if ($product->price === null) {
                    $minVariantPrice = $product->variants()
                        ->whereNotNull('price')
                        ->where('price', '>', 0)
                        ->min('price');

                    if ($minVariantPrice !== null) {
                        $product->price = $minVariantPrice;
                    }
                }
                return $product;
            });

            return ProductResource::collection($products)
                ->additional([
                    'meta' => [
                        'current_page' => $products->currentPage(),
                        'last_page'    => $products->lastPage(),
                        'per_page'     => $products->perPage(),
                        'total'        => $products->total(),
                        'has_more'     => $products->hasMorePages(),
                        'prev_page'    => $products->previousPageUrl(),
                        'next_page'    => $products->nextPageUrl(),
                    ]
                ]);
        } catch (\Throwable $e) {

            return response()->json([
                'status'  => 'error',
                'message' => 'Impossible de récupérer les produits.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

     /**
     * Affiche les détails d’un produit
     */
    public function showAdmin(int $id)
    {
        try {
            $product = $this->productService->findById($id);

            return response()->json([
                'success' => true,
                'data' => new ProductDetailResource($product)
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Produit introuvable'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur interne du serveur',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

   /**
     * Enregistre un produit avec ses variantes et images Cloudinary
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $images = $request->file('images', []);

            $product = $this->productService->createProductWithVariantsAndImages($data, $images);

            return response()->json([
                'message' => ' Produit créé avec succès',
                'data' => $product
            ], 201);

        } catch (\Throwable $e) {
            Log::error(" Erreur Cloudinary : {$e->getMessage()}");

            return response()->json([
                'message' => 'Une erreur est survenue lors de la création avec Cloudinary',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     *  Crée un produit simple
     */

    public function storeSimple(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id'    => 'required|exists:categories,id',
            'audience_id'    => 'nullable|integer|exists:audiences,id',
            'name'           => 'required|string|max:255',
            'description'    => 'nullable|string',
            'sku'            => 'nullable|string|max:50|unique:products,sku',
            'price'          => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'is_active'      => 'boolean',
            'is_blocked'     => 'boolean',
            'images'         => 'nullable|array',
            'images.*'       => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        try {
            $images = $request->file('images', []);

            // 🔹 Récupère automatiquement le vendor_id du vendeur connecté
            $vendor = auth()->user()->vendor;
            if (!$vendor) {
                return response()->json([
                    'message' => 'Utilisateur non enregistré comme vendeur.'
                ], 403);
            }
            $validated['vendor_id'] = $vendor->id;

            $product = $this->productService->createSimpleProduct($validated, $images);

            return response()->json([
                'message' => 'Produit créé avec succès sur Cloudinary',
                'data'    => $product,
            ], 201);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la création du produit : ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Erreur lors de la création du produit',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    public function updateSimple(Request $request, string|int $id): JsonResponse
    {
        // Convertir hash en ID si nécessaire pour la validation
        $productId = is_numeric($id) ? (int) $id : app(\App\Services\SqidsService::class)->decode($id);
        
        if ($productId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Produit introuvable'
            ], 404);
        }

        $validated = $request->validate([
            'category_id'    => 'nullable|exists:categories,id',
            'audience_id'    => 'nullable|integer|exists:audiences,id',
            'name'           => 'nullable|string|max:255',
            'description'    => 'nullable|string',
            'sku'            => 'nullable|string|max:50|unique:products,sku,' . $productId,
            'price'          => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'is_active'      => 'nullable|boolean',
            'is_blocked'     => 'nullable|boolean',
            'images'         => 'nullable|array',
            'images.*'       => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        try {
            $images = $request->file('images', []);

            // Utiliser l'ID décodé
            $product = $this->productService->updateSimpleProduct($productId, $validated, $images);

            return response()->json([
                'message' => 'Produit mis à jour avec succès',
                'data'    => $product,
            ], 200);

        } catch (Exception $e) {
            Log::error('Erreur lors de la mise à jour du produit : ' . $e->getMessage(), [
                'product_id' => $id,
                'user_id'    => Auth::id(),
            ]);

            return response()->json([
                'message' => 'Erreur lors de la mise à jour du produit',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }




    public function Upload(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|image|max:5120',
            ]);

            $file = $request->file('file');

            $uploadResult = CloudinaryFacade::uploadApi()->upload(
                $file->getRealPath(),
                [
                    'folder' => 'Messagerie-mS',
                    'resource_type' => 'image',
                ]
            );

            $url = $uploadResult['secure_url'];

            return response()->json([
                'data' => $url
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                "message" => "Le fichier doit être une image valide.",
                "errors" => $e->errors()
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                "message" => "Erreur lors de l'upload !",
                "error_type" => get_class($th),
                "line" => $th->getLine(),
                "file" => $th->getFile(),
                "errors" => $th->getMessage()
            ], 500);
        }
    }

     /**
     * Affiche les détails d'un produit
     */
    public function showVendeur(string|int $id)
    {
        try {
            // Accepter ID ou hash
            $product = $this->productService->findById($id, true); // true pour exclure les produits supprimés

            return response()->json([
                'success' => true,
                'data' => new ProductDetailVResource($product)
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Produit introuvable'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur interne du serveur',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

       /**
     * Supprimer une image de produit
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroyImage(int $id): JsonResponse
    {
        try {
            $image = $this->productService->deleteProductImage($id);

            return response()->json([
                'success' => true,
                'message' => 'Image supprimée avec succès',
                'data' => $image
            ], 200);

        } catch (Exception $e) {
            Log::error('Erreur lors de la suppression de l\'image : ' . $e->getMessage(), [
                'image_id' => $id,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }




}
