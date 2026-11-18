<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateOrderRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PayPalPaymentService;
use App\Services\SecurePaymentService;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class OrdersController extends Controller
{
    protected $orderService;
    protected $paypalService;

    public function __construct(OrderService $orderService, PayPalPaymentService $paypalService)
    {
        $this->orderService = $orderService;
        $this->paypalService = $paypalService;
    }

    /**
     * GET /orders — liste paginée + compteurs par statut + CA total
     */
    public function index(Request $request)
    {
        $user = JWTAuth::user();
        if (!$user || !$user->vendor) {
            return response()->json([
                'success' => true,
                'data' => [
                    'orders' => [
                        'data' => [],
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => (int) $request->get('per_page', 10),
                        'total' => 0,
                    ],
                    'counts' => [
                        'in_preparation' => 0,
                        'to_be_collected' => 0,
                        'collected' => 0,
                        'canceled' => 0,
                        'abandoned' => 0,
                        'all' => 0,
                    ],
                    'totalRevenue' => 0,
                ],
            ]);
        }

        try {
            $vendorId = $user->vendor->id;

            $filters = $request->only([
                'per_page', 'page', 'status', 'paymentMethod',
                'period', 'start_date', 'end_date', 'q',
                'order_number', 'include'
            ]);

            $data = $this->orderService->getOrdersList($vendorId, $filters);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue',
            ], 500);
        }
    }

    /**
     * Détails d'une commande (items + variants + images)
     */
    public function show(Request $request, int $orderId)
    {
        Log::info('=== FONCTION SHOW APPELÉE ===', ['orderId' => $orderId]);
        $user = JWTAuth::user();
        if (!$user || !$user->vendor) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 401);
        }

        $vendorId = $user->vendor->id;

        // Relations nécessaires
        $with = [
            'user:id,first_name,last_name,email',
            'items.productVariant',
            'items.product:id,name',
            'items.product.images',
        ];

        $order = Order::with($with)
            ->where('vendor_id', $vendorId)
            ->where('id', $orderId)
            ->first();

        Log::info('show() - Recherche commande', [
            'vendorId' => $vendorId,
            'orderId' => $orderId,
            'found' => $order ? true : false
        ]);

        if (!$order) {
            Log::error('show() - Commande introuvable', ['vendorId' => $vendorId, 'orderId' => $orderId]);
            return response()->json([
                'success' => false,
                'message' => 'Commande introuvable',
            ], 404);
        }

        $result = [
            'id' => $order->id,
            'orderNumber' => $order->order_number,
            'createdAt' => $order->created_at->toISOString(),
            'status' => $order->status,
            'amount' => (float) $order->total_amount,
            'paymentMethod' => $order->payment_method,
            'paymentStatus' => $order->payment_status,
            'customer' => [
                'name' => $order->user ? ($order->user->first_name . ' ' . $order->user->last_name) : 'Client inconnu',
                'email' => $order->user->email ?? 'N/A',
            ],
            'items' => $order->items->map(function ($item) {
                $images = optional($item->product)->images
                    ? $item->product->images->map(function ($img) {
                        return [
                            'id' => $img->id,
                            'path' => $img->path,
                            'alt' => $img->alt,
                            'isPrimary' => (bool) $img->is_primary,
                            'position' => (int) $img->position,
                        ];
                    })->values()->toArray()
                    : [];

                $variantPrice = data_get($item, 'productVariant.price');
                return [
                    'id' => $item->id,
                    'productId' => $item->product_id,
                    'productVariantId' => $item->product_variant_id,
                    'quantity' => (int) $item->quantity,
                    'unitPrice' => (float) $item->unit_price,
                    'lineTotal' => (float) $item->unit_price * (int) $item->quantity,
                    'product' => [
                        'name' => optional($item->product)->name,
                        'images' => $images,
                    ],
                    'variant' => [
                        'sku' => data_get($item, 'productVariant.sku'),
                        'size' => data_get($item, 'productVariant.size'),
                        'color' => data_get($item, 'productVariant.color'),
                        'price' => $variantPrice !== null ? (float) $variantPrice : null,
                    ],
                ];
            })->values(),
        ];

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Données financières par statut
     */
    public function getFinanceData(Request $request)
    {
        $user = JWTAuth::user();

        if (!$user?->vendor) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 401);
        }

        $vendorId = $user->vendor->id;

        $data = $this->orderService->getFinanceData(
            $vendorId,
            $request->get('period'),
            $request->get('start_date'),
            $request->get('end_date')
        );

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * commandes validées et remboursées pour la page finance
     */
    public function getOrderValidatedAndRefunded(Request $request)
    {
        $user = JWTAuth::user();

        if (!$user?->vendor) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 401);
        }

        $vendorId = $user->vendor->id;

        $data = $this->orderService->getOrderValidatedAndRefunded(
            $vendorId,
            $request->get('period'),
            $request->get('start_date'),
            $request->get('end_date'),
            $request->get('search'),
            $request->get('status')
        );

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }


    /**
     * Marquer une commande comme prête
     */
    public function markAsReady(Request $request, int $orderId)
    {
        $user = JWTAuth::user();
        if (!$user || !$user->vendor) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 401);
        }

        try {
            $result = $this->orderService->markAsReady($orderId, $user->vendor->id);
            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue',
            ], 500);
        }
    }

    /**
     * Marquer une commande comme récupérée
     */
    public function markAsCollected(Request $request, int $orderId)
    {
        $user = JWTAuth::user();
        if (!$user || !$user->vendor) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 401);
        }

        try {
            $result = $this->orderService->markAsCollected($orderId, $user->vendor->id);
            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue',
            ], 500);
        }
    }

    /**
     * Marquer une commande comme abandonnée
     */
    public function markAsAbandoned(Request $request, int $orderId)
    {
        $user = JWTAuth::user();
        if (!$user || !$user->vendor) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 401);
        }

        try {
            $result = $this->orderService->markAsAbandoned($orderId, $user->vendor->id);
            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue',
            ], 500);
        }
    }

    /**
     * Rembourser une commande
     */
    public function refundOrder(Request $request, int $orderId)
    {
        $user = JWTAuth::user();
        if (!$user || !$user->vendor) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 401);
        }

        try {
            $result = $this->orderService->refundOrder($orderId, $user->vendor->id);
            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue',
            ], 500);
        }
    }

    /**
     * Marquer une commande comme remboursée
     */
    public function markAsRefunded(Request $request, int $orderId)
    {
        $user = JWTAuth::user();
        if (!$user || !$user->vendor) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 401);
        }

        try {
            $result = $this->orderService->markAsRefunded($orderId, $user->vendor->id);
            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors du remboursement',
            ], 500);
        }
    }

    /**
     * Annuler une commande
     */
    public function cancelOrder(Request $request, int $orderId)
    {
        $user = JWTAuth::user();
        if (!$user || !$user->vendor) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 401);
        }

        try {
            $result = $this->orderService->cancelOrder($orderId, $user->vendor->id);
            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue',
            ], 500);
        }
    }

    /**
     * Annuler une commande avec raison et notification email
     */
    public function cancelOrderWithReason(Request $request, int $orderId)
    {
        Log::info('=== DEBUT cancelOrderWithReason ===', [
            'orderId' => $orderId,
            'request_data' => $request->all()
        ]);

        $user = JWTAuth::user();
        if (!$user || !$user->vendor) {
            Log::warning('Utilisateur non autorisé', [
                'has_user' => !is_null($user),
                'has_vendor' => $user ? !is_null($user->vendor) : false
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 401);
        }

        Log::info('Utilisateur et vendeur valides', [
            'user_id' => $user->id,
            'vendor_id' => $user->vendor->id
        ]);

        $request->validate([
            'reason' => 'required|string|min:10|max:500',
        ]);

        try {
            Log::info('Appel du service cancelOrderWithReason', [
                'orderId' => $orderId,
                'vendorId' => $user->vendor->id,
                'reason' => $request->input('reason')
            ]);

            $result = $this->orderService->cancelOrderWithReason(
                $orderId, 
                $user->vendor->id, 
                $request->input('reason')
            );

            Log::info('Service cancelOrderWithReason terminé avec succès', $result);
            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            Log::error('Erreur InvalidArgumentException', [
                'message' => $e->getMessage(),
                'orderId' => $orderId
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur générale dans cancelOrderWithReason', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'orderId' => $orderId
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue',
            ], 500);
        }
    }

    /**
     * Marquer une commande comme en préparation (validation)
     */
    public function markAsInPreparation(Request $request, int $orderId)
    {
        $user = JWTAuth::user();
        if (!$user || !$user->vendor) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 401);
        }

        try {
            $result = $this->orderService->markAsInPreparation($orderId, $user->vendor->id);
            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue',
            ], 500);
        }
    }

    /**
     * Valider une commande avec date de retrait et notification email
     */
    public function validateOrderWithPickupDate(Request $request, int $orderId)
    {
        Log::info('=== DEBUT validateOrderWithPickupDate ===', [
            'orderId' => $orderId,
            'request_data' => $request->all()
        ]);

        $user = JWTAuth::user();
        if (!$user || !$user->vendor) {
            Log::warning('Utilisateur non autorisé', [
                'has_user' => !is_null($user),
                'has_vendor' => $user ? !is_null($user->vendor) : false
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 401);
        }

        Log::info('Utilisateur et vendeur valides', [
            'user_id' => $user->id,
            'vendor_id' => $user->vendor->id
        ]);

        $request->validate([
            'pickup_date' => 'required|date|after_or_equal:today',
        ]);

        try {
            Log::info('Appel du service validateOrderWithPickupDate', [
                'orderId' => $orderId,
                'vendorId' => $user->vendor->id,
                'pickup_date' => $request->input('pickup_date')
            ]);

            $result = $this->orderService->validateOrderWithPickupDate(
                $orderId, 
                $user->vendor->id, 
                $request->input('pickup_date')
            );

            Log::info('Service validateOrderWithPickupDate terminé avec succès', $result);
            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            Log::error('Erreur InvalidArgumentException', [
                'message' => $e->getMessage(),
                'orderId' => $orderId
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur générale dans validateOrderWithPickupDate', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'orderId' => $orderId
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue',
            ], 500);
        }
    }

    /**
     * Marquer une commande comme prête et notifier le client
     */
    public function notifyOrderReady(Request $request, int $orderId)
    {
        Log::info('=== DEBUT notifyOrderReady ===', [
            'orderId' => $orderId
        ]);

        $user = JWTAuth::user();
        if (!$user || !$user->vendor) {
            Log::warning('Utilisateur non autorisé', [
                'has_user' => !is_null($user),
                'has_vendor' => $user ? !is_null($user->vendor) : false
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 401);
        }

        Log::info('Utilisateur et vendeur valides', [
            'user_id' => $user->id,
            'vendor_id' => $user->vendor->id
        ]);

        try {
            Log::info('Appel du service notifyOrderReady', [
                'orderId' => $orderId,
                'vendorId' => $user->vendor->id
            ]);

            $result = $this->orderService->notifyOrderReady($orderId, $user->vendor->id);

            Log::info('Service notifyOrderReady terminé avec succès', $result);
            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            Log::error('Erreur InvalidArgumentException', [
                'message' => $e->getMessage(),
                'orderId' => $orderId
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur générale dans notifyOrderReady', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'orderId' => $orderId
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue',
            ], 500);
        }
    }

    /**
     * Notifier le client (pour les commandes abandonnées)
     */
    public function notifyCustomer(Request $request, int $orderId)
    {
        $user = JWTAuth::user();
        if (!$user || !$user->vendor) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 401);
        }

        try {
            $result = $this->orderService->notifyCustomer($orderId, $user->vendor->id);
            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue',
            ], 500);
        }
    }

    private function resolveDateRange(?string $period, ?string $startDate, ?string $endDate): array
    {
        if ($startDate && $endDate) {
            try {
                $start = Carbon::parse($startDate)->startOfDay();
                $end = Carbon::parse($endDate)->endOfDay();
                return [$start, $end];
            } catch (\Throwable $e) {
                return [null, null];
            }
        }

        if ($period === 'today') {
            return [Carbon::today(), Carbon::now()];
        }
        if ($period === 'week') {
            return [Carbon::now()->startOfWeek(), Carbon::now()];
        }
        if ($period === 'month') {
            return [Carbon::now()->startOfMonth(), Carbon::now()];
        }

        return [null, null];
    }

    /**
     * Affiche la liste des orders avec pagination
     */
    public function indexAdmin(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);

            $orders = $this->orderService->getPaginated(
                $perPage,
                $request->only(['status', 'payment_status', 'payment_method'])
            );

            // On retourne directement la pagination Laravel
            return response()->json($orders);
        } catch (\Throwable $e) {

            return response()->json([
                'status'  => 'error',
                'message' => 'Impossible de récupérer les commandes.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }



    public function getOrdersByClient(Request $request)
    {
        try {
            $user = JWTAuth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non autorisé',
                ], 401);
            }

            $orders = $this->orderService->listClientOrders($user, $request->query());

            return response()->json([
                'success' => true,
                'data' => $orders,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de récupérer les commandes.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function showClientOrder(Order $order)
    {
        try {
            $user = JWTAuth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non autorisé',
                ], 401);
            }

            // Vérifier que la commande appartient à l'utilisateur connecté
            if ($order->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Commande non trouvée.',
                ], 404);
            }

            // Charger les relations nécessaires + factures + VENDEUR
            $order->load([
                'user:id,first_name,last_name,email',
                'vendor', // Sans spécifier les colonnes
                'vendor.user', // Sans spécifier les colonnes
                'items.productVariant',
                'items.product:id,name',
                'items.product.images',
                'invoices:id,order_id,invoice_number,cloudinary_public_id,cloudinary_url,generated_at,status,file_size',
                'transactions' => function ($query) {
                    $query->where('type', 'payment')->where('status', 'completed');
                }
            ]);

            Log::info("INFO APRES LOAD VENDOR", array($order));

            // Récupérer les détails de paiement si la commande est payée
            $paymentDetails = null;
            if ($order->payment_status === 'complete' && $order->transactions->isNotEmpty()) {
                $paymentDetails = $this->getEnhancedPaymentDetails($order);
            }

            // Récupérer les factures avec URLs de téléchargement
            $invoices = $order->invoices->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'generated_at' => $invoice->generated_at ? $invoice->generated_at->toISOString() : null,
                    'status' => $invoice->status,
                    'file_size_mb' => $invoice->file_size ? round($invoice->file_size / 1024 / 1024, 2) : 0,
                    'download_url' => route('invoice.download', $invoice->id),
                    'can_download' => !empty($invoice->cloudinary_public_id),
                    'direct_cloudinary_url' => $invoice->cloudinary_url
                ];
            });

            // Déterminer l'adresse du vendeur (priorité à user, fallback sur vendor)
            $vendorAddress = $order->vendor->user ? [
                'street' => $order->vendor->user->street,
                'postal_code' => $order->vendor->user->postal_code,
                'city' => $order->vendor->user->city,
            ] : [
                'street' => $order->vendor->rue,
                'postal_code' => $order->vendor->code_postal,
                'city' => $order->vendor->ville,
            ];

            $result = [
                'id' => $order->id,
                'orderNumber' => $order->order_number,
                'createdAt' => $order->created_at->toISOString(),
                'status' => $order->status,
                'amount' => (float) $order->total_amount,
                'paymentMethod' => $order->payment_method,
                'paymentStatus' => $order->payment_status,

                // Informations client
                'customer' => [
                    'name' => $order->user ? ($order->user->first_name . ' ' . $order->user->last_name) : 'Client inconnu',
                    'email' => $order->user->email ?? 'N/A',
                ],

                // AJOUTÉ: Informations vendeur
                'vendor' => [
                    'id' => $order->vendor->id,
                    'name_store' => $order->vendor->name_store,
                    'description' => $order->vendor->description,
                    'website_url' => $order->vendor->website_url,
                    'email' => optional($order->vendor->user)->email,
                    'phone' => optional($order->vendor->user)->phone,
                    'contact' => [
                        'email' => optional($order->vendor->user)->email,
                        'phone' => optional($order->vendor->user)->phone,
                    ],
                    'address' => [
                        'street' => $vendorAddress['street'],
                        'postal_code' => $vendorAddress['postal_code'],
                        'city' => $vendorAddress['city'],
                        'formatted' => trim(($vendorAddress['street'] ?? '') . ', ' .
                            ($vendorAddress['postal_code'] ?? '') . ' ' .
                            ($vendorAddress['city'] ?? ''))
                    ]
                ],

                'items' => $order->items->map(function ($item) {
                    $images = optional($item->product)->images
                        ? $item->product->images->map(function ($img) {
                            return [
                                'id' => $img->id,
                                'path' => $img->path,
                                'alt' => $img->alt,
                                'isPrimary' => (bool) $img->is_primary,
                                'position' => (int) $img->position,
                            ];
                        })->values()->toArray()
                        : [];

                    $variantPrice = data_get($item, 'productVariant.price');
                    return [
                        'id' => $item->id,
                        'productId' => $item->product_id,
                        'productVariantId' => $item->product_variant_id,
                        'quantity' => (int) $item->quantity,
                        'unitPrice' => (float) $item->unit_price,
                        'lineTotal' => (float) $item->unit_price * (int) $item->quantity,
                        'product' => [
                            'name' => optional($item->product)->name,
                            'images' => $images,
                        ],
                        'variant' => [
                            'sku' => data_get($item, 'productVariant.sku'),
                            'size' => data_get($item, 'productVariant.size'),
                            'color' => data_get($item, 'productVariant.color'),
                            'price' => $variantPrice !== null ? (float) $variantPrice : null,
                        ],
                    ];
                })->values(),

                // Informations de paiement et factures
                'payment' => $paymentDetails,
                'invoices' => $invoices->values()->toArray(),
                'hasInvoices' => $invoices->isNotEmpty(),
                'invoicesCount' => $invoices->count(),

                // Informations de livraison si disponibles
                'shipping' => [
                    'address' => $order->shipping_address,
                    'cost' => (float) ($order->shipping_cost ?? 0),
                    'tracking_number' => $order->tracking_number,
                    'estimated_delivery' => $order->estimated_delivery_date ? $order->estimated_delivery_date->toISOString() : null,
                ],

                // Totaux détaillés
                'totals' => [
                    'subtotal' => (float) $order->items->sum(fn($item) => $item->quantity * $item->unit_price),
                    'shipping' => (float) ($order->shipping_cost ?? 0),
                    'tax' => (float) ($order->tax_amount ?? 0),
                    'discount' => (float) ($order->discount_amount ?? 0),
                    'total' => (float) $order->total_amount
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $result,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de récupérer la commande.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Récupérer les détails enrichis du paiement (INCHANGÉ mais cohérent)
     */
    private function getEnhancedPaymentDetails(Order $order): ?array
    {
        try {
            $transaction = $order->transactions->first();

            if (!$transaction || !$transaction->external_transaction_id) {
                return [
                    'method' => $order->payment_method,
                    'display' => ucfirst($order->payment_method ?? 'Non défini'),
                    'status' => 'Payé',
                    'date' => $order->updated_at->format('d/m/Y H:i')
                ];
            }

            // Récupérer les détails selon la méthode de paiement
            if ($order->payment_method === 'stripe') {
                Log::info("ICI");
                return $this->getStripePaymentDetails($transaction->external_transaction_id, $order);
            } elseif ($order->payment_method === 'paypal') {
                return $this->getPayPalPaymentDetails($transaction->external_transaction_id, $order);
            }

            return [
                'method' => $order->payment_method,
                'display' => ucfirst($order->payment_method),
                'status' => 'Payé',
                'date' => $order->updated_at->format('d/m/Y H:i')
            ];
        } catch (Exception $e) {
            return [
                'method' => $order->payment_method,
                'display' => ucfirst($order->payment_method ?? 'Méthode inconnue'),
                'status' => 'Payé',
                'date' => $order->updated_at->format('d/m/Y H:i'),
                'error' => 'Détails non disponibles'
            ];
        }
    }

    /**
     * Détails Stripe avec cache (INCHANGÉ)
     */
    private function getStripePaymentDetails(string $paymentIntentId, Order $order): array
    {
        // Configurer Stripe si pas déjà fait
        Stripe::setApiKey(config('services.stripe.secret'));

        $cacheKey = "stripe_payment_details_{$paymentIntentId}";

        return Cache::remember($cacheKey, now()->addHours(24), function () use ($paymentIntentId, $order) {
            try {
                $paymentIntent = PaymentIntent::retrieve([
                    'id' => $paymentIntentId,
                    'expand' => ['payment_method']
                ]);

                $paymentMethod = $paymentIntent->payment_method;

                if ($paymentMethod && $paymentMethod->type === 'card') {
                    $card = $paymentMethod->card;
                    return [
                        'method' => 'stripe',
                        'type' => 'card',
                        'display' => 'Carte bancaire',
                        'brand' => ucfirst($card->brand),
                        'last4' => $card->last4,
                        'exp_month' => str_pad($card->exp_month, 2, '0', STR_PAD_LEFT),
                        'exp_year' => $card->exp_year,
                        'formatted' => ucfirst($card->brand) . ' •••• ' . $card->last4,
                        'expires' => str_pad($card->exp_month, 2, '0', STR_PAD_LEFT) . '/' . substr($card->exp_year, -2),
                        'status' => 'Payé',
                        'date' => $order->updated_at->format('d/m/Y H:i'),
                        'transaction_id' => $paymentIntentId
                    ];
                }

                return [
                    'method' => 'stripe',
                    'display' => 'Stripe',
                    'status' => 'Payé',
                    'date' => $order->updated_at->format('d/m/Y H:i'),
                    'transaction_id' => $paymentIntentId
                ];
            } catch (Exception $e) {
                Log::error("ERROR DEATILS STRIPE PAYMENT", array($e));
                return [
                    'method' => 'stripe',
                    'display' => 'Carte bancaire',
                    'formatted' => 'Carte bancaire',
                    'status' => 'Payé',
                    'date' => $order->updated_at->format('d/m/Y H:i'),
                    'transaction_id' => $paymentIntentId,
                    'error' => 'Détails non disponibles'
                ];
            }
        });
    }

    /**
     * Détails PayPal avec cache (INCHANGÉ)
     */
    private function getPayPalPaymentDetails(string $captureId, Order $order): array
    {
        $cacheKey = "paypal_payment_details_{$captureId}";

        return Cache::remember($cacheKey, now()->addHours(24), function () use ($captureId, $order) {
            try {
                $securePaymentService = app(SecurePaymentService::class);
                $paypalService = new PayPalPaymentService($securePaymentService);

                $token = $paypalService->getAccessToken();

                $response = Http::timeout(30)
                    ->withToken($token)
                    ->get($paypalService->getBaseUrl() . "/v2/payments/captures/{$captureId}");

                if ($response->successful()) {
                    $capture = $response->json();

                    $payer = $capture['payer'] ?? [];

                    // Extraction sécurisée des données du payeur
                    $payerEmail = $payer['email_address'] ?? null;
                    $payerName = '';

                    if (isset($payer['name'])) {
                        $firstName = $payer['name']['given_name'] ?? '';
                        $lastName = $payer['name']['surname'] ?? '';
                        $payerName = trim($firstName . ' ' . $lastName);
                    }

                    // Fallback sur d'autres champs possibles
                    if (empty($payerName) && isset($payer['payer_info'])) {
                        $payerName = $payer['payer_info']['first_name'] . ' ' . $payer['payer_info']['last_name'] ?? '';
                        $payerName = trim($payerName);
                    }

                    // Construction du formatted avec fallbacks
                    $formatted = 'PayPal';
                    if (!empty($payerEmail)) {
                        $formatted .= ' (' . $payerEmail . ')';
                    } elseif (!empty($payerName)) {
                        $formatted .= ' (' . $payerName . ')';
                    }

                    return [
                        'method' => 'paypal',
                        'type' => 'paypal',
                        'display' => 'PayPal',
                        'payer_email' => $payerEmail,
                        'payer_name' => $payerName ?: null,
                        'formatted' => $formatted,
                        'status' => 'Payé',
                        'date' => $order->updated_at->format('d/m/Y H:i'),
                        'transaction_id' => $captureId,
                        'capture_status' => $capture['status'] ?? null,
                        'capture_amount' => $capture['amount']['value'] ?? null,
                        'capture_currency' => $capture['amount']['currency_code'] ?? 'EUR'
                    ];
                }

                // Si l'API PayPal échoue, retourner les infos de base
                return $this->getPayPalFallbackDetails($captureId, $order);

            } catch (Exception $e) {
                Log::error("Erreur détails paiement PayPal", [
                    'capture_id' => $captureId,
                    'error' => $e->getMessage(),
                    'order_id' => $order->id
                ]);

                return $this->getPayPalFallbackDetails($captureId, $order);
            }
        });
    }

    /**
     * Détails PayPal de fallback quand l'API échoue
     */
    private function getPayPalFallbackDetails(string $captureId, Order $order): array
    {
        return [
            'method' => 'paypal',
            'type' => 'paypal',
            'display' => 'PayPal',
            'formatted' => 'PayPal',
            'status' => 'Payé',
            'date' => $order->updated_at->format('d/m/Y H:i'),
            'transaction_id' => $captureId,
            'error' => 'Détails non disponibles',
            'note' => 'Les détails PayPal ne sont pas accessibles'
        ];
    }

    public function store(CreateOrderRequest $request)
    {
        try {
            $user = JWTAuth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non autorisé',
                ], 401);
            }
            
            $validatedData = $request->validated();
            $orders = $this->orderService->createOrder($user, $validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Commande créée avec succès.',
                'data' => $orders,
            ], 201);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de créer la commande.',
                'error' => $e->getMessage(),
            ], 500);
        } 
    }

    public function cancel(Order $order)
    {
        try {
            $user = JWTAuth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non autorisé',
                ], 401);
            }

            // Vérifier que la commande appartient à l'utilisateur connecté
            if ($order->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Commande non trouvée.',
                ], 404);
            }

            $canceledOrder = $this->orderService->clientCancelOrder($order);

            return response()->json([
                'success' => true,
                'message' => 'Commande annulée avec succès.',
                'data' => $canceledOrder,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible d\'annuler la commande.',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    public function clientMarkAsCollected(Order $order)
    {
        try {
            $user = JWTAuth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non autorisé',
                ], 401);
            }

            // Vérifier que la commande appartient à l'utilisateur connecté
            if ($order->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Commande non trouvée.',
                ], 404);
            }

            $collectedOrder = $this->orderService->clientMarkAsCollected($order);

            return response()->json([
                'success' => true,
                'message' => 'Commande marquée comme collectée.',
                'data' => $collectedOrder,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de marquer la commande comme collectée.',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    public function showOrder($id)
    {
        try {
            $order = $this->orderService->findById((int) $id);

            return response()->json([
                'status' => 'success',
                'data'   => $order
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Commande introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Affiche les détails d'une commande pour un client (avec JWT)
     */
    public function showClientOrderDetails(Request $request, int $orderId)
    {
        $user = JWTAuth::user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 401);
        }

        // Relations nécessaires
        $with = [
            'user:id,first_name,last_name,email',
            'items.productVariant',
            'items.product:id,name',
            'items.product.images',
        ];

        // Si l'utilisateur est un vendeur, chercher par vendor_id
        // Sinon, chercher par user_id (client)
        if ($user->vendor) {
            $order = Order::with($with)
                ->where('vendor_id', $user->vendor->id)
                ->where('id', $orderId)
                ->first();
        } else {
            $order = Order::with($with)
                ->where('user_id', $user->id)
                ->where('id', $orderId)
                ->first();
        }

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Commande introuvable',
            ], 404);
        }

        $result = [
            'id' => $order->id,
            'orderNumber' => $order->order_number,
            'createdAt' => $order->created_at->toISOString(),
            'status' => $order->status,
            'amount' => (float) $order->total_amount,
            'paymentMethod' => $order->payment_method,
            'paymentStatus' => $order->payment_status === 'complete',
            'customer' => [
                'name' => $order->user ? ($order->user->first_name . ' ' . $order->user->last_name) : 'Client inconnu',
                'email' => $order->user->email ?? 'N/A',
            ],
            'items' => $order->items->map(function ($item) {
                $images = optional($item->product)->images
                    ? $item->product->images->map(function ($img) {
                        return [
                            'id' => $img->id,
                            'path' => $img->path,
                            'alt' => $img->alt,
                            'isPrimary' => (bool) $img->is_primary,
                            'position' => (int) $img->position,
                        ];
                    })->values()->toArray()
                    : [];

                $variantPrice = data_get($item, 'productVariant.price');
                return [
                    'id' => $item->id,
                    'productId' => $item->product_id,
                    'productVariantId' => $item->product_variant_id,
                    'quantity' => (int) $item->quantity,
                    'unitPrice' => (float) $item->unit_price,
                    'lineTotal' => (float) $item->unit_price * (int) $item->quantity,
                    'product' => [
                        'name' => optional($item->product)->name,
                        'images' => $images,
                    ],
                    'variant' => [
                        'sku' => data_get($item, 'productVariant.sku'),
                        'size' => data_get($item, 'productVariant.size'),
                        'color' => data_get($item, 'productVariant.color'),
                        'price' => $variantPrice !== null ? (float) $variantPrice : null,
                    ],
                ];
            })->values(),
        ];

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Marquer une commande comme récupérée
     */
    public function markAsPickedUp(Request $request, int $orderId)
    {
        try {
            $user = JWTAuth::user();
            if (!$user || !$user->vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non autorisé',
                ], 401);
            }
            
            $vendorId = $user->vendor->id;
            
            $result = $this->orderService->markAsPickedUp($orderId, $vendorId);
            
            return response()->json([
                'success' => true,
                'message' => 'Commande marquée comme récupérée et client notifié avec succès',
                'data' => $result
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors du marquage de la commande comme récupérée'
            ], 500);
        }
    }

    /**
     * Export des transactions en CSV
     */
    public function exportTransactionsExcel(Request $request)
    {
        try {
            $user = JWTAuth::user();
            if (!$user || !$user->vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }

            $vendorId = $user->vendor->id;
            
            // Récupérer les paramètres de filtrage
            $filters = $request->only([
                'period', 'start_date', 'end_date', 'search', 'status', 'type'
            ]);

            // Récupérer les données des transactions
            $transactions = $this->orderService->getTransactionsForExport($vendorId, $filters);
            
            // Debug: vérifier le nombre de transactions
            Log::info('Export CSV - Nombre de transactions trouvées: ' . $transactions->count());
            Log::info('Export CSV - Filtres appliqués: ', $filters);

            // Préparer les données pour Excel
            $excelData = $transactions->map(function ($transaction) {
                return [
                    'Numéro de commande' => $transaction['order_number'],
                    'Date' => $transaction['date'],
                    'Type' => $transaction['type'] === 'sale' ? 'Vente' : 'Remboursement',
                    'Client' => $transaction['customer']['name'] ?? 'N/A',
                    'Email' => $transaction['customer']['email'] ?? 'N/A',
                    'Montant' => number_format($transaction['amount'], 2, ',', ' '),
                    'Statut' => $this->getStatusLabel($transaction['status'])
                ];
            });

            // Créer le contenu CSV pour Excel
            $headers = ['Numéro de commande', 'Date', 'Type', 'Client', 'Email', 'Montant', 'Statut'];
            
            $csvContent = $this->arrayToCsv($excelData->toArray(), $headers);
            
            // Ajouter BOM pour UTF-8
            $bom = "\xEF\xBB\xBF";
            $csvContent = $bom . $csvContent;

            $filename = 'faet_transactions_' . now()->format('Y-m-d_H-i-s') . '.csv';

            return response($csvContent)
                ->header('Content-Type', 'text/csv; charset=utf-8')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');

        } catch (\Exception $e) {
            Log::error('Erreur export CSV transactions: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'export CSV'
            ], 500);
        }
    }

    /**
     * Export des transactions en JSON
     */
    public function exportTransactionsJson(Request $request)
    {
        try {
            $user = JWTAuth::user();
            if (!$user || !$user->vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }

            $vendorId = $user->vendor->id;
            
            // Récupérer les paramètres de filtrage
            $filters = $request->only([
                'period', 'start_date', 'end_date', 'search', 'status', 'type'
            ]);

            // Récupérer les données des transactions
            $transactions = $this->orderService->getTransactionsForExport($vendorId, $filters);

            // Préparer les données JSON
            $jsonData = [
                'export_info' => [
                    'date' => now()->toISOString(),
                    'vendor_id' => $vendorId,
                    'count' => $transactions->count(),
                    'filters' => $filters
                ],
                'transactions' => $transactions->map(function ($transaction) {
                    return [
                        'order_number' => $transaction['order_number'],
                        'date' => $transaction['date'],
                        'type' => $transaction['type'],
                        'customer' => [
                            'name' => $transaction['customer']['name'] ?? 'N/A',
                            'email' => $transaction['customer']['email'] ?? 'N/A'
                        ],
                        'amount' => $transaction['amount'],
                        'status' => $transaction['status'],
                        'status_label' => $this->getStatusLabel($transaction['status'])
                    ];
                })
            ];

            $filename = 'faet_transactions_' . now()->format('Y-m-d_H-i-s') . '.json';

            return response()->json($jsonData)
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');

        } catch (\Exception $e) {
            Log::error('Erreur export JSON transactions: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'export JSON'
            ], 500);
        }
    }

    /**
     * Convertir un tableau en CSV
     */
    private function arrayToCsv(array $data, array $headers = null)
    {
        $output = fopen('php://temp', 'r+');
        
        if ($headers) {
            fputcsv($output, $headers, ';');
        }
        
        foreach ($data as $row) {
            fputcsv($output, $row, ';');
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }

    /**
     * Obtenir le libellé du statut
     */
    private function getStatusLabel($status)
    {
        $statusLabels = [
            'to_be_collected' => 'À retirer',
            'collected' => 'Récupéré',
            'abandoned' => 'Abandonnée',
            'refunded' => 'Remboursée'
        ];
        
        return $statusLabels[$status] ?? $status;
    }
}