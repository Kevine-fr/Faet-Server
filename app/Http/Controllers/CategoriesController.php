<?php

namespace App\Http\Controllers;

use App\Services\CategoryService;
use App\Http\Requests\CategoryFilterRequest;
use Illuminate\Http\Request;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use App\Http\Resources\CategoryAudienceResource;
use Exception;
use Illuminate\Support\Facades\Cache;


class CategoriesController extends Controller
{
    protected $categoryService;

    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    /**
     * Ajouter une catégorie
     */

     public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name'        => 'required|string|max:100|unique:categories,name',
                'description' => 'nullable|string',
                'parent_id'   => 'nullable|exists:categories,id',
                'is_active'   => 'boolean'
            ]);

            $category = $this->categoryService->create($validated);

            return response()->json([
                'message'  => 'Catégorie créée avec succès',
                'category' => $category
            ], 201);

        } catch (Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue lors de la création de la catégorie.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bloquer/Désactiver une catégorie
     */
    public function deactivate($id)
    {
        try {
            $this->categoryService->deactivate($id);

            return response()->json([
                'message' => 'Catégorie désactivée avec succès'
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Catégorie introuvable.'
            ], 404);

        } catch (Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue lors de la désactivation de la catégorie.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Réactiver une catégorie
     *
     * Note: This method assumes that the CategoryService has an activate method.
     */
    public function activate($id)
    {
        try {
            $this->categoryService->activate($id);
            return response()->json(['message' => 'Catégorie réactivée']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Catégorie introuvable'], 404);
        }
    }
    /**
     * Récupérer les catégories paginées
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */

    public function index()
    {
        try {
                $categories = Category::select('id', 'name')
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get();

                return response()->json([
                    'status' => 'success',
                    'data'   => $categories,
                ]);
            } catch (\Throwable $e) {

                return response()->json([
                    'status'  => 'error',
                    'message' => 'Impossible de récupérer les catégories.',
                    'error'   => $e->getMessage(),
                ], 500);
            }
    }

    /**
     * Récupérer toutes les catégories sans pagination
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function getAll()
    {
        $categories = $this->categoryService->getAll();
        return CategoryResource::collection($categories);
    }

    /**
     * Récupérer une catégorie par son ID
     *
     * @param int $id
     * @return CategoryResource
     * @throws ModelNotFoundException
     * @throws Exception
     */
    public function show($id)
    {
        try {
            $category = $this->categoryService->findById($id);
            return new CategoryResource($category);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Catégorie introuvable'], 404);
        }
    }

    /**
     * Mettre à jour une catégorie
     *
     * @param Request $request
     * @param int $id
     * @return CategoryResource
     */
    public function update(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'name'        => 'sometimes|string|max:100|unique:categories,name,' . $id,
                'description' => 'nullable|string',
                'parent_id'   => 'nullable|exists:categories,id',
                'is_active'   => 'boolean'
            ]);

            $category = $this->categoryService->update($id, $validated);

            return new CategoryResource($category);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Catégorie introuvable'], 404);
        }
    }

    /**
     * Récupérer les catégories avec leurs sous-catégories
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCategoriesWithSubcategories()
    {
        try {
            $categories = $this->categoryService->getAll();

            // Organiser les catégories en hiérarchie
            // Utiliser filter() au lieu de where() pour les Collections
            $mainCategories = $categories->filter(function ($category) {
                return $category->parent_id === null;
            });
            
            $subCategories = $categories->filter(function ($category) {
                return $category->parent_id !== null;
            });

            $result = [];

            foreach ($mainCategories as $mainCategory) {
                $mainCategoryData = [
                    'id' => $mainCategory->id,
                    'name' => $mainCategory->name,
                    'description' => $mainCategory->description,
                    'is_active' => $mainCategory->is_active,
                    'subcategories' => []
                ];

                // Ajouter les sous-catégories
                foreach ($subCategories as $subCategory) {
                    if ($subCategory->parent_id == $mainCategory->id) {
                        $mainCategoryData['subcategories'][] = [
                            'id' => $subCategory->id,
                            'name' => $subCategory->name,
                            'description' => $subCategory->description,
                            'is_active' => $subCategory->is_active,
                            'parent_id' => $subCategory->parent_id
                        ];
                    }
                }

                $result[] = $mainCategoryData;
            }

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Catégories récupérées avec succès'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des catégories',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retourne les catégories racines pour un select
     */
    public function getRootCategories()
    {
        try {
            $categories = $this->categoryService->getRootCategoriesForSelect();

            return response()->json([
                'status' => 'success',
                'data' => $categories
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Impossible de récupérer les catégories : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Affiche les produits d'une catégorie avec filtres
    */
    public function showCatDetailsForClient (Request $request, Category $category): JsonResponse
    {
        $validatedData = $request->validate([
            'subcategories' => 'array|nullable',
            'subcategories.*' => 'integer|exists:categories,id',
            'price_min' => 'numeric|min:0|nullable',
            'price_max' => 'numeric|min:0|nullable',
            'colors' => 'array|nullable',
            'colors.*' => 'string',
            'sizes' => 'array|nullable',
            'sizes.*' => 'string',
            'sort_by' => 'string|in:name,price,created_at,newest,recent,oldest,recently_updated|nullable',
            'sort_order' => 'string|in:asc,desc|nullable',
            'per_page' => 'integer|min:1|max:50|nullable',
        ]);

        // Validation personnalisée pour price_min et price_max
        if (isset($validatedData['price_min']) && isset($validatedData['price_max'])) {
            if ($validatedData['price_min'] > $validatedData['price_max']) {
                return response()->json([
                    'error' => 'Le prix minimum ne peut pas être supérieur au prix maximum'
                ], 422);
            }
        }

        // Valider que les sous-catégories appartiennent bien à la catégorie principale
        if (isset($validatedData['subcategories'])) {
            $validSubcategories = Cache::remember(
                "category_children_{$category->id}",
                3600,
                fn() => $category->children()->pluck('id')->toArray()
            );

            $invalidSubcategories = array_diff($validatedData['subcategories'], $validSubcategories);

            if (!empty($invalidSubcategories)) {
                return response()->json([
                    'error' => 'Certaines sous-catégories ne sont pas valides pour cette catégorie'
                ], 422);
            }
        }

        $perPage = $validatedData['per_page'] ?? 12;

        try {
            $result = $this->categoryService->getCategoryProducts(
                $category,
                $validatedData,
                $perPage,
                auth()->user()
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'category' => [
                        'id' => $category->id,
                        'name' => $category->name,
                        'description' => $category->description,
                    ],
                    'subcategories' => $result['subcategories']->map(function($subcategory) {
                        return [
                            'id' => $subcategory->id,
                            'name' => $subcategory->name,
                            'description' => $subcategory->description,
                        ];
                    }),
                    'products' => [
                        'data' => $result['products']->getCollection()->map(function($product) {
                            return $this->formatProductData($product);
                        }),
                        'pagination' => [
                            'current_page' => $result['products']->currentPage(),
                            'last_page' => $result['products']->lastPage(),
                            'per_page' => $result['products']->perPage(),
                            'total' => $result['products']->total(),
                            'from' => $result['products']->firstItem(),
                            'to' => $result['products']->lastItem(),
                        ]
                    ],
                    'total_count' => $result['total_count'],
                    'filters_applied' => $result['filters_applied'],
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Une erreur est survenue lors de la récupération des produits',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupère les options de filtrage pour une catégorie
    */
    public function getFilterOptions(Category $category): JsonResponse
    {
        try {
            $colors = $this->categoryService->getAvailableColors($category);
            $sizes = $this->categoryService->getAvailableSizes($category);
            $priceRange = $this->categoryService->getPriceRange($category);
            $subCategory = $this->categoryService->getSubcategories($category);

            return response()->json([
                'success' => true,
                'data' => [
                    'colors' => $colors,
                    'sizes' => $sizes,
                    'price_range' => $priceRange,
                    'sub_categories' => $subCategory,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Une erreur est survenue lors de la récupération des options de filtrage',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Formate les données d'un produit pour l'API
     */
    private function formatProductData($product): array
    {
        // Utiliser le prix précalculé
        $effectivePrice = $product->effective_price ?? $product->price;

        // Image principale déjà filtrée par la relation
        $primaryImage = $product->images->first();

        // Optimiser le traitement des variants
        $activeVariants = $product->variants->where('is_active', true);

        $availableColors = $activeVariants->pluck('color')->filter()->unique()->values();
        $availableSizes = $activeVariants->pluck('size')->filter()->unique()->values();

        $variantPrices = $activeVariants->pluck('price')->filter();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'sku' => $product->sku,
            'price' => $effectivePrice,
            'stock_quantity' => $product->stock_quantity,
            'image' => $primaryImage ? [
                'path' => $primaryImage->path,
                'alt' => $primaryImage->alt,
            ] : null,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
            ] : [
                'id' => null,
                'name' => 'Aucune catégorie',
            ],
            'has_variants' => $activeVariants->count() > 0,
            'available_colors' => $availableColors,
            'available_sizes' => $availableSizes,
            'variant_count' => $activeVariants->count(),
            'is_favorite' => $product->is_favorite ?? false,
            'price_range' => $variantPrices->count() > 0 ? [
                'min' => $variantPrices->min(),
                'max' => $variantPrices->max(),
            ] : null,
        ];
    }


    public function storeWithAuience(Request $request)
    {
        try {
            $validated = $request->validate([
                'name'        => 'required|string|max:100|unique:categories,name',
                'description' => 'nullable|string',
                'parent_id'   => 'nullable|exists:categories,id',
                'is_active'   => 'boolean',
                'audience_ids' => 'nullable|array',
                'audience_ids.*' => 'exists:audiences,id',
            ]);

            // Création de la catégorie
            $category = $this->categoryService->createWithAudeince($validated);

            // Si l'utilisateur a fourni des audiences, on crée les associations
            if (!empty($validated['audience_ids'])) {
                $category->audiences()->sync($validated['audience_ids']);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Catégorie créée avec succès',
                'data' => $category->load('audiences') // renvoie la catégorie avec les audiences associées
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation échouée',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la création de la catégorie.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function updateWithAudience(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'name' => 'sometimes|string|max:100|unique:categories,name,' . $id,
                'description' => 'nullable|string',
                'parent_id' => 'nullable|exists:categories,id',
                'is_active' => 'sometimes|boolean',
                'audience_ids' => 'nullable|array',
                'audience_ids.*' => 'exists:audiences,id',
            ]);

            $category = $this->categoryService->updateCategory($id, $validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Catégorie mise à jour avec succès.',
                'data' => $category
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Données invalides.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Catégorie introuvable.'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Impossible de mettre à jour la catégorie.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

     /**
     * GET /categories-with-relations
     * Catégories triées (récentes → anciennes) avec:
     * - parent = nom du parent
     * - audiences = liste des noms
     */
    public function indexWithRelations(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 20);

            $categories = $this->categoryService->getAllWithParentAndAudiences(
                $perPage,
                $request->only(['only_parents', 'only_children', 'is_active'])
            );

            return response()->json([
                'status' => 'success',
                'data'   => CategoryAudienceResource::collection($categories),
                'links'  => [
                    'first' => $categories->url(1),
                    'last'  => $categories->url($categories->lastPage()),
                    'prev'  => $categories->previousPageUrl(),
                    'next'  => $categories->nextPageUrl(),
                ],
                'meta'   => [
                    'current_page' => $categories->currentPage(),
                    'from'         => $categories->firstItem(),
                    'last_page'    => $categories->lastPage(),
                    'per_page'     => $categories->perPage(),
                    'to'           => $categories->lastItem(),
                    'total'        => $categories->total(),
                ],
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'status'  => 'error',
                'message' => 'Impossible de récupérer les catégories.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


  public function getNavCategories()
    {
        try {
            $categories = Category::whereNull('parent_id')
                ->select('id', 'name')
                ->with(['children' => function ($query) {
                    $query->select('id', 'name', 'parent_id');
                }])
                ->orderBy('name')
                ->get();

            return response()->json([
                'message' => 'Catégories récupérées avec succès',
                'success' => true,
                'error' => false,
                'data' => $categories
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erreur lors de la récupération des catégories',
                'success' => false,
                'error' => true,
                'data' => null,
                // 'exception' => $e->getMessage() // active seulement pour debug
            ], 500);
        }
    }


    /**
     * Récupère toutes les sous-catégories actives par ordre alphabétique.
     */
    public function getSubCategories()
    {
        $subCategories = Category::whereNotNull('parent_id')
            ->where('is_active', true)
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'count' => $subCategories->count(),
            'data' => $subCategories
        ]);
    }
    



}