<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;

// --- Contrôleurs côté GitHub ---
use App\Http\Controllers\CategoriesController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AudienceController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\VendorsController;

// --- Contrôleurs côté Local (HEAD) ---
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PlatformDetectionController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\VendorDocumentsController;
use App\Http\Controllers\VendorStoreLocationController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\DemographyController;
use App\Http\Controllers\FavoritesController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SynchronizationController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\VendorSubscriptionsController;
use App\Http\Controllers\DashboardAdminController;
use App\Http\Controllers\CategoryAudienceController;
use App\Http\Controllers\InvoicesController;
use App\Http\Controllers\PayPalPaymentController;
use App\Http\Controllers\ProductSearchController;
use App\Http\Controllers\PromoCodeController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\PromotionProductController;
use App\Http\Controllers\StripePaymentController;
use App\Http\Controllers\StripeConnectController;
use App\Http\Controllers\TransactionsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ProductVariantController;
use App\Http\Controllers\TitlePromotionController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/* ================================
|            GITHUB (TOP)
|=============================== */

/********************************** Client ********************************* */

Route::prefix('auth')->group(function () {
    // Routes Auth Client
    Route::post('client-register', [AuthController::class, 'clientRegister']);
    Route::post('verify-email-code', [AuthController::class, 'verifyEmailCode']);
    Route::post('resend-verify-email-code', [AuthController::class, 'clientResendVerifyEmailCode']);
    Route::post('client-login', [AuthController::class, 'clientLogin']);
    Route::post('forgot-password-request', [AuthController::class, 'clientForgotPasswordRequest']);
    Route::patch('reset-forgot-password', [AuthController::class, 'clientResetForgotPassword']);

    // Routes Auth Google
    Route::get('google/redirect', [AuthController::class, 'clientRedirectToGoogle']);
    Route::get('google/callback', [AuthController::class, 'clientHandleGoogleCallback']);

    // Auth client
    Route::middleware(['jwt.cookie', 'jwt.auth'])->group(function () {
        Route::get('user', [AuthController::class, 'getAuthClient']);
        Route::patch('change-password', [AuthController::class, 'clientChangePassword']);
        Route::patch('update-profile', [AuthController::class, 'clienUpdateProfile']);
        Route::get('client-logout', [AuthController::class, 'clientLogout']);
        Route::delete('delete-account', [AuthController::class, 'clientDeleteAccount']);
    });
});

Route::middleware(['jwt.cookie', 'jwt.auth'])->group(function () {
    Route::patch('set-location', [UserController::class, 'setLocation']);
});

Route::prefix('shop')->group(function () {

    Route::prefix('products')->group(function () {
        Route::get('/', [ProductsController::class, 'clientGetProducts'])
        ->middleware('jwt.cookie');

        Route::get('/new', [ProductsController::class, 'getNewProducts'])
        ->middleware('jwt.cookie');

        Route::get('/promotions', [ProductsController::class, 'getPromotionProducts'])
        ->middleware('jwt.cookie');

        Route::get('/search', [ProductSearchController::class, 'search']);

        Route::get('/popular', [ProductSearchController::class, 'popular']);

        Route::get('/suggestions', [ProductSearchController::class, 'suggestions']); // Autocomplete
    });

    Route::get('products/{id}', [ProductsController::class, 'clientGetProduct'])
        ->middleware('jwt.cookie');

    Route::post('/products/similar', [ProductsController::class, 'getSimilarProducts'])
        ->middleware('jwt.cookie');

});

Route::prefix('vendors')->group(function () {
    // Récupération des détails du vendeur et ses produits
    Route::get('{vendorId}', [VendorsController::class, 'getVendorShop'])
        ->middleware('jwt.cookie');

    // Recherche de produits d'un vendeur spécifique
    Route::get('{vendorId}/search', [VendorsController::class, 'searchVendorProducts'])
        ->middleware('jwt.cookie');
});

Route::middleware(['jwt.cookie', 'jwt.auth'])->group(function () {
    Route::get('favorites', [FavoritesController::class, 'index']);
    Route::post('favorites', [FavoritesController::class, 'store']);
    Route::delete('favorites/{productId}', [FavoritesController::class, 'destroy']);
});

Route::middleware(['jwt.cookie', 'jwt.auth'])->group(function () {
    // Lister les abonnements de l'utilisateur connecté
    Route::get('subscriptions', [VendorSubscriptionsController::class, 'index']);
    // S'abonner à un vendeur
    Route::post('vendors/{vendor}/subscribe', [VendorSubscriptionsController::class, 'store']);
    // Se désabonner d'un vendeur
    Route::delete('vendors/{vendor}/unsubscribe', [VendorSubscriptionsController::class, 'destroy']);
});

Route::middleware(['jwt.cookie', 'jwt.auth'])->group(function () {

    // Routes pour les commandes client
    Route::get('client/orders', [OrdersController::class, 'getOrdersByClient']);
    Route::post('client/orders', [OrdersController::class, 'store']);
    Route::get('client/orders/{order}', [OrdersController::class, 'showClientOrder']);
    Route::patch('client/orders/{order}/cancel', [OrdersController::class, 'cancel']);
    Route::patch('client/orders/{order}/collect', [OrdersController::class, 'clientMarkAsCollected']);

    // Route alternative pour les détails de commande avec JWT
    Route::get('client/orders/{orderId}/details', [OrdersController::class, 'showClientOrderDetails']);
});

// routes/api.php
Route::middleware(['jwt.cookie', 'jwt.auth'])->group(function () {
    // Télécharger facture (génération à la volée)
    Route::get('orders/{order}/invoice/download', [InvoicesController::class, 'downloadInvoice']);

    // Prévisualiser facture dans le navigateur
    Route::get('orders/{order}/invoice/preview', [InvoicesController::class, 'previewInvoice']);
});

Route::prefix('categories')->group(function () {
    Route::get('/nav-cats', [CategoriesController::class, 'getNavCategories']);

    // Récupérer les produits d'une catégorie avec filtres et pagination
    // jwt.cookie permet de récupérer l'utilisateur connecté s'il existe
    Route::get('{category}/products', [CategoriesController::class, 'showCatDetailsForClient'])
        ->middleware('jwt.cookie')
        ->name('category.products');

    // Récupérer les options de filtrage pour une catégorie
    Route::get('{category}/filter-options', [CategoriesController::class, 'getFilterOptions'])
        ->name('category.filter-options');
});

Route::prefix('promo-codes')->group(function () {
    Route::get('/active', [PromoCodeController::class, 'getActivePromoCodes']);
});

Route::get('/audience', [AudienceController::class, 'index']);

Route::prefix('promotions')->group(function () {
    // Vérifier si des promos/codes sont actifs (pour afficher le banner)
    Route::get('/has-active', [PromotionController::class, 'hasActivePromotions']);
    // Page promotions : tous les produits en promo ou avec codes
    Route::get('/products', [PromotionController::class, 'getPromotionProducts'])->middleware('jwt.cookie');

    Route::prefix('promo-codes')->group(function () {
        Route::post('/available-for-cart', [PromoCodeController::class, 'getAvailablePromoCodesForProducts']);
        Route::post('/validate', [PromoCodeController::class, 'validatePromoCode']);
    });
});


// Routes Stripe Connect (avec authentification sécurisée)
// Route::middleware(['auth:api'])->group(function () {
//     Route::prefix('stripe')->group(function () {
//         Route::post('/create-checkout-session', [StripePaymentController::class, 'createCheckoutSession']);
//         Route::post('/check-status', [StripePaymentController::class, 'checkPaymentStatus']);
//         Route::post('/refund', [StripePaymentController::class, 'refundPayment']);
//     });
// });

// Routes Stripe Connect Express (avec authentification sécurisée)
Route::middleware(['auth:api'])->group(function () {
    Route::prefix('stripe-connect')->group(function () {
        // Création et gestion du compte Express
        Route::post('/create-account', [StripeConnectController::class, 'createAccount']);
        Route::post('/onboarding-link', [StripeConnectController::class, 'createOnboardingLink']);
        Route::post('/login-link', [StripeConnectController::class, 'createLoginLink']);
        Route::get('/account-status', [StripeConnectController::class, 'getAccountStatus']);
        Route::post('/update-status', [StripeConnectController::class, 'updateStatus']);
        Route::delete('/delete-account', [StripeConnectController::class, 'deleteAccount']);
        Route::post('/onboarding-callback', [StripeConnectController::class, 'handleOnboardingCallback']);
    });
});

Route::middleware(['jwt.cookie', 'jwt.auth', 'throttle:payment-api', 'verified', 'secure.headers'])->group(function () {

    // PAIEMENTS MULTIPLES (panier avec plusieurs vendeurs) STRIPE
    Route::prefix('stripe')->group(function () {
        Route::post('/create-checkout-session', [StripePaymentController::class, 'createCheckoutSession']);
        Route::post('/check-status', [StripePaymentController::class, 'checkPaymentStatus']);
        Route::post('/refund', [StripePaymentController::class, 'refundPayment']);
    });

    // PAIEMENTS MULTIPLES (panier avec plusieurs vendeurs) PAYPAL
    Route::prefix('paypal')->group(function () {
        Route::post('/create-order', [PayPalPaymentController::class, 'createPayPalOrder']);
        Route::post('/capture', [PayPalPaymentController::class, 'capturePayPalPayment']);
        Route::post('/refund', [PayPalPaymentController::class, 'refundPayPalPayment']);
        Route::get('/order-status/{paypalOrderId}', [PayPalPaymentController::class, 'getPayPalOrderStatus']);
    });

    // PAIEMENTS INDIVIDUELS (une commande à la fois)
    Route::prefix('orders/{orderId}/payment')->group(function () {
        // PayPal
        Route::prefix('paypal')->group(function () {
            Route::post('/create', [PayPalPaymentController::class, 'createSingleOrderPayPal']);
            Route::post('/capture', [PayPalPaymentController::class, 'captureSingleOrderPayPal']);
        });
    });
});

// Webhooks (sans authentification mais avec vérification de signature)
Route::middleware(['throttle:webhook'])->group(function () {
    Route::post('/stripe/webhook', [StripePaymentController::class, 'handleWebhook']);
    Route::post('/stripe-connect/webhook', [StripeConnectController::class, 'handleWebhook']);
    Route::post('/paypal/webhook', [PayPalPaymentController::class, 'handlePayPalWebhook']);
});

// Callbacks PayPal (sans authentification pour les redirections)
Route::middleware(['throttle:60,1', 'secure.headers'])->group(function () {
    Route::prefix('paypal')->group(function () {
        // Callbacks pour paiements multiples
        Route::get('/success', [PayPalPaymentController::class, 'handlePayPalApproval']);
        Route::get('/cancel', [PayPalPaymentController::class, 'handlePayPalCancel']);

        // Callbacks pour paiements individuels
        Route::get('/single-order/success', [PayPalPaymentController::class, 'handleSingleOrderApproval']);
        Route::get('/single-order/cancel', [PayPalPaymentController::class, 'handleSingleOrderCancel']);
    });
});

Route::get('/invoice/{invoice}/download', [InvoicesController::class, 'download'])->name('invoice.download');

/**********************************************Admin******************/
// Routes Auth Admin
Route::post('/Admin-login', [AuthController::class, 'AdminLogin'])
->middleware('auth.throttle');
Route::get('/search/admins/reindex', [AdminController::class, 'reindex']);
Route::post('/Admin-logout', [AdminController::class, 'logout']);

Route::middleware(['auth:api', 'role:admin|vendeur'])->group(function () {
    Route::get('/show-produit-admin/{id}', [ProductController::class, 'showAdmin']);
});

// Routes Admin
Route::middleware(['auth:api', 'role:admin'])->group(function () {

    /**Dashbord Admin */
    Route::get('/Dashboard', [DashboardAdminController::class, 'index']);

    /** Auth Admin*/
    Route::get('/me-admin', [AuthController::class, 'me']);
    Route::put('/admins-update-me', [AdminController::class, 'updateMe']);

    /** User */
    Route::patch('/users/{id}/block', [AdminController::class, 'block']);
    Route::patch('/users/{id}/activate', [AdminController::class, 'activate']);
    Route::get('/users/{id}', [AdminController::class,
     'show']);

    /** Admin */
    Route::post('/admin', [AdminController::class, 'store']);
    Route::get('/admins', [AdminController::class, 'index']);
    //  Route::get('/search/admins', [AdminController::class, 'searchAdmins']);

    /**Audience*/
    Route::get('/audiences/{id}', [AudienceController::class, 'show']);
    Route::post('/audiences', [AudienceController::class, 'store']);
    Route::put('/audiences/{id}', [AudienceController::class, 'update']);
    Route::delete('/audiences/{id}', [AudienceController::class, 'destroy']);

    /* Client */
    Route::post('/clients', [ClientController::class, 'storeClient']);
    Route::get('/clients', [ClientController::class, 'lisClient']);

    /* Vendors */
    Route::get('/vendors', [VendorsController::class, 'lisVendeur']);
    Route::post('/vendors', [VendorsController::class, 'storeVendor']);
    Route::get('/vendors/detail/{id}', [VendorsController::class, 'show']);

    /* Category */
    Route::post('/categories', [CategoriesController::class, 'store']);
    Route::get('/categories', [CategoriesController::class, 'index']);
    Route::get('/categories/listecategorie', [CategoriesController::class, 'getRootCategories']);
    Route::get('/categories/{id}', [CategoriesController::class, 'show']);
    Route::put('/categories/{id}', [CategoriesController::class, 'update']);
    Route::patch('/categories/{id}/deactivate', [CategoriesController::class, 'deactivate']);
    Route::patch('/categories/{id}/activate', [CategoriesController::class, 'activate']);

    /**Audeice Categorie */
    Route::post('/categories/audiences', [CategoriesController::class, 'storeWithAuience']);
    Route::get('/categories-with-relations', [CategoriesController::class, 'indexWithRelations']);
    Route::put('/categories/audiences/{id}', [CategoriesController::class, 'updateWithAudience']);

    /*produits */
    // Route::get('/productsAdmin', [ProductController::class, 'indexAdmin']);
    // Route::get('/show-produit-admin/{id}', [ProductController::class, 'showAdmin']);
    Route::get('/productsAdmin', [ProductController::class, 'indexAdmin']);

    /**Commande */
    Route::get('/Commande', [OrdersController::class, 'indexAdmin']);
    Route::get('/Commande/{id}', [OrdersController::class, 'showOrder']);

    
    /**Transaction */
    Route::get('/transactions', [TransactionsController::class, 'index']);

    /**Promotions*/
    Route::get('/promotions-admin', [TitlePromotionController::class, 'index']);
    Route::get('/promotions-admin/{id}', [TitlePromotionController::class, 'show']);
    Route::post('/promotions-admin/', [TitlePromotionController::class, 'store']);
    Route::put('/promotions-admin/{id}', [TitlePromotionController::class, 'update']);
    Route::delete('/promotions-admin/{id}', [TitlePromotionController::class, 'destroy']);
    Route::post('/promotions-admin/{id}/restore', [TitlePromotionController::class, 'restore']);

    /*Code promo **/
    Route::get('/admin/promo-codes', [PromoCodeController::class, 'indexAdmin']);
    Route::get('/admin/promo-codes/{id}', [PromoCodeController::class, 'showAdmin']);


});

/* ================================
|         LOCAL (HEAD) — BOTTOM
|=============================== */

// Routes Auth Client (versions locales)
Route::post('/client-register', [AuthController::class, 'clientRegister']);
Route::post('/verify-email-code', [AuthController::class, 'verifyEmailCode']);
Route::post('/resend-verification-code', [AuthController::class, 'resendVerifiCode']);
Route::post('/client-login', [AuthController::class, 'clientLogin']);
Route::post('/forgot-password-request', [AuthController::class, 'clientForgotPasswordRequest']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/verify-reset-token', [AuthController::class, 'verifyResetToken']);

Route::post('/vendor-register', [AuthController::class, 'vendorRegister']);
Route::post('/vendor-login', [AuthController::class, 'vendorLogin']);

// Détection de plateforme
Route::post('/detect-platform', [PlatformDetectionController::class, 'detect']);

    // Route publique pour récupérer les catégories (nécessaire pour le frontend)
    Route::get('/categories', [CategoriesController::class, 'index']);
    Route::get('/categories-all', [CategoriesController::class, 'getAll']);

// E-commerce (protégé par auth)
Route::middleware('auth:api')->group(function () {
    Route::post('/save-ecommerce-config', [PlatformDetectionController::class, 'saveEcommerceConfig']);
    Route::post('/save-ecommerce-config-no-category', [PlatformDetectionController::class, 'saveEcommerceConfigNoCategory']);
    Route::get('/vendor/platform-status', [PlatformDetectionController::class, 'getPlatformStatus']);
    Route::get('/vendor/products', [PlatformDetectionController::class, 'getVendorProducts']);
});

// Routes vendeur / tableau de bord / etc. (protégé par auth)
Route::middleware('auth:api')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/user', [AuthController::class, 'getUser']);
    Route::get('/user/{id}', [AuthController::class, 'getUser']);

    // Vérification d'auth
    Route::get('/check-auth', [AuthController::class, 'checkAuth']);

    // Profil
    Route::put('/update-profile', [AuthController::class, 'updateProfile']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // Catégories avec sous-catégories (pour la page product-categories)
    Route::get('/categories-with-subcategories', [CategoriesController::class, 'getCategoriesWithSubcategories']);

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/stats', [DashboardController::class, 'getStats']);
    Route::get('/dashboard/order-status', [DashboardController::class, 'getOrderStatus']);
    Route::get('/dashboard/recent-orders', [DashboardController::class, 'getRecentOrders']);

    // Orders (liste + filtres)
    Route::get('/orders', [OrdersController::class, 'index']);
    Route::get('/orders/finance', [OrdersController::class, 'getFinanceData']);
    Route::get('/orders/validated-and-refunded', [OrdersController::class, 'getOrderValidatedAndRefunded']);

    // Actions sur les commandes (routes spécifiques AVANT la route générique)
    Route::patch('/orders/{orderId}/mark-ready', [OrdersController::class, 'markAsReady']);
    Route::patch('/orders/{orderId}/notify-ready', [OrdersController::class, 'notifyOrderReady']);
    Route::patch('/orders/{orderId}/mark-collected', [OrdersController::class, 'markAsCollected']);
    Route::patch('/orders/{orderId}/mark-picked-up', [OrdersController::class, 'markAsPickedUp']);
    Route::patch('/orders/{orderId}/mark-abandoned', [OrdersController::class, 'markAsAbandoned']);
    Route::patch('/orders/{orderId}/refund', [OrdersController::class, 'refundOrder']);
    Route::patch('/orders/{orderId}/mark-refunded', [OrdersController::class, 'markAsRefunded']);
    Route::patch('/orders/{orderId}/cancel', [OrdersController::class, 'cancelOrder']);
    Route::patch('/orders/{orderId}/cancel-with-reason', [OrdersController::class, 'cancelOrderWithReason']);
    Route::patch('/orders/{orderId}/notify-customer', [OrdersController::class, 'notifyCustomer']);
    Route::patch('/orders/{orderId}/validate-with-pickup-date', [OrdersController::class, 'validateOrderWithPickupDate']);
    Route::patch('/orders/{orderId}/in-preparation', [OrdersController::class, 'markAsInPreparation']);

    // Route générique (à placer en dernier)
    Route::get('/orders/{orderId}', [OrdersController::class, 'show']);

    // Clients
    Route::get('/customers', [CustomerController::class, 'getCustomers']);
    Route::get('/customers/stats', [CustomerController::class, 'getCustomerStats']);
    Route::get('/customers/{customerId}', [CustomerController::class, 'getCustomerDetails']);

    // Documents vendeur
    Route::get('/vendor-documents', [VendorDocumentsController::class, 'index']);
    Route::post('/vendor-documents', [VendorDocumentsController::class, 'store']);
    Route::get('/vendor-documents/{vendorDocument}', [VendorDocumentsController::class, 'show']);
    Route::put('/vendor-documents/{vendorDocument}', [VendorDocumentsController::class, 'update']);
    Route::delete('/vendor-documents/{vendorDocument}', [VendorDocumentsController::class, 'destroy']);
    Route::get('/vendor-documents/{vendorDocument}/download', [VendorDocumentsController::class, 'download']);

    // Politiques
    Route::get('/policies', [PolicyController::class, 'index']);
    Route::post('/policies', [PolicyController::class, 'store']);
    Route::delete('/policies', [PolicyController::class, 'destroy']);

    // Méthodes de paiement
    Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
    Route::get('/payment-methods/types', [PaymentMethodController::class, 'getAvailableTypes']);
    Route::get('/payment-methods/{id}', [PaymentMethodController::class, 'show']);
    Route::post('/payment-methods', [PaymentMethodController::class, 'store']);
    Route::put('/payment-methods/{id}', [PaymentMethodController::class, 'update']);
    Route::delete('/payment-methods/{id}', [PaymentMethodController::class, 'destroy']);
    Route::post('/payment-methods/{id}/set-default', [PaymentMethodController::class, 'setDefault']);

    // Démographie
    Route::get('/demography/city', [DemographyController::class, 'getCityDemography']);
    Route::post('/demography/cities', [DemographyController::class, 'getMultipleCitiesDemography']);

    // Notifications (préférences via VendorsController)
    Route::get('/notifications/preferences', [\App\Http\Controllers\VendorsController::class, 'getNotificationPreferences']);
    Route::put('/notifications/preferences', [\App\Http\Controllers\VendorsController::class, 'updateNotificationPreferences']);

    // Notifications système
    Route::get('/notifications', [NotificationController::class, 'getVendorNotifications']);
    Route::get('/notifications/latest', [NotificationController::class, 'getLatestNotification']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'deleteNotification']);

    // Synchronisation
    Route::post('/synchronization/test-connection', [SynchronizationController::class, 'testConnection']);
    Route::post('/synchronization/check-website-url', [SynchronizationController::class, 'checkWebsiteUrlAvailability']);
    Route::post('/synchronization/fetch-shop-data', [SynchronizationController::class, 'fetchShopData']);
    Route::post('/synchronization/sync-shop', [SynchronizationController::class, 'synchronizeShop']);
    Route::get('/synchronization/status', [SynchronizationController::class, 'getSyncStatus']);
    Route::post('/synchronization/sync-product-images', [SynchronizationController::class, 'syncProductImages']);
    Route::post('/synchronization/sync-all-product-images', [SynchronizationController::class, 'syncAllProductImages']);
    Route::post('/synchronization/smart-sync', [SynchronizationController::class, 'smartSync']);

    // Produits (contrôleur "ProductController" version locale)
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{id}', [ProductController::class, 'show']);
    Route::get('/products/{id}/images', [ProductController::class, 'getProductImages']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);

    // Nouvelles routes pour la gestion des catégories (simplifiées)
    Route::patch('/products/{id}/category', [ProductController::class, 'updateProductCategory']);
    Route::patch('/products/category', [ProductController::class, 'updateMultipleProductsCategory']);


    // Codes promo
    Route::get('/promo-codes', [\App\Http\Controllers\PromoCodeController::class, 'index']);
    Route::get('/promo-codes/{id}', [\App\Http\Controllers\PromoCodeController::class, 'show']);
    Route::post('/promo-codes', [\App\Http\Controllers\PromoCodeController::class, 'store']);
    Route::put('/promo-codes/{id}', [\App\Http\Controllers\PromoCodeController::class, 'update']);
    Route::delete('/promo-codes/{id}', [\App\Http\Controllers\PromoCodeController::class, 'destroy']);
    Route::patch('/promo-codes/{id}/toggle-status', [\App\Http\Controllers\PromoCodeController::class, 'toggleStatus']);
    Route::get('/promo-codes/products/available', [\App\Http\Controllers\PromoCodeController::class, 'getAvailableProducts']);
    Route::get('/promo-codes/generate/code', [\App\Http\Controllers\PromoCodeController::class, 'generateCode']);
    Route::get('/promo-codes/stats', [\App\Http\Controllers\PromoCodeController::class, 'getStats']);

    // Attachement des codes promo aux produits
    Route::post('/promo-codes/attach-products', [\App\Http\Controllers\AttachPromoCodeController::class, 'attachProducts']);
    Route::delete('/promo-codes/{promoCodeId}/detach-product/{productId}', [\App\Http\Controllers\AttachPromoCodeController::class, 'detachProduct']);
    Route::get('/promo-codes/{promoCodeId}/attached-products', [\App\Http\Controllers\AttachPromoCodeController::class, 'getAttachedProducts']);
    Route::get('/promo-codes/attached-products/all', [\App\Http\Controllers\AttachPromoCodeController::class, 'getAllAttachedProducts']);
    Route::post('/promo-codes/calculate-final-price', [\App\Http\Controllers\AttachPromoCodeController::class, 'calculateFinalPrice']);

    // Promotions
    Route::get('/promotions', [PromotionController::class, 'index']);
    Route::get('/promotions/stats', [PromotionController::class, 'getStats']);
    Route::get('/promotions/{id}', [PromotionController::class, 'show']);
    Route::post('/promotions', [PromotionController::class, 'store']);
    Route::put('/promotions/{id}', [PromotionController::class, 'update']);
    Route::delete('/promotions/{id}', [PromotionController::class, 'destroy']);
    Route::patch('/promotions/{id}/toggle-status', [PromotionController::class, 'toggleStatus']);

    // Promotion Product (associations)
    Route::get('/promotions/{promotionId}/products', [PromotionProductController::class, 'getByPromotion']);
    Route::post('/promotions/{promotionId}/sync', [PromotionProductController::class, 'sync']);
});


/**********************************************Faet lance******************/

// Route::post('/vendor-login', [AuthController::class, 'vendorLogin']);

Route::middleware(['auth:api', 'role:vendeur'])->group(function () {

        Route::get('/vendor/store-location', [VendorStoreLocationController::class, 'show']);
        Route::post('/vendor/store-location', [VendorStoreLocationController::class, 'update']);

        Route::put('/create-estore', [VendorsController::class, 'estore']);
        Route::post('/vendor/update-info', [VendorsController::class, 'updateInfo']);
        // Création d’un produit
        Route::post('/products', [ProductController::class, 'storeSimple']);
        // Mise à jour d’un produit existant
        Route::put('/products/{id}', [ProductController::class, 'updateSimple']);
        //Ajout produit variants
        Route::post('/product-variants', [ProductVariantController::class, 'storeVariant']);
        //Details Produits
        Route::get('/show-produit-vendeur/{id}', [ProductController::class, 'showVendeur']);
        // Route pour supprimer une image de produit
        Route::delete('/product-images/{id}', [ProductController::class, 'destroyImage']);
        //Sous categorie
        Route::get('/subcategories', [CategoriesController::class, 'getSubCategories']);

        // Vérifier si une promotion title est active
        Route::get('/title-promotions/check-active', [TitlePromotionController::class, 'checkActive']);
        Route::get('/title-promotions/list', [TitlePromotionController::class, 'listForSelect']);




    });

// Route::post('/products-variants', [ProductController::class, 'store']);
// Route::post('/upload', [ProductController::class, 'Upload']);
