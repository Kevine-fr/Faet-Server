<?php

namespace App\Services;

use App\Mail\ClientOrderConfirmationMail;
use App\Mail\VendorOrderNotificationMail;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OrderEmailNotificationService
{
    private $invoiceGenerator;

    public function __construct(InvoiceGeneratorService $invoiceGenerator)
    {
        $this->invoiceGenerator = $invoiceGenerator;
    }

    /**
     * Envoyer l'email de confirmation au client (SANS facture en PJ, juste un lien)
     */
    public function sendClientOrderConfirmation(array $orders): array
    {
        try {
            Log::info('CONTENU DE ORDER', ['orders' => $orders]);
            if (empty($orders)) {
                throw new Exception('Aucune commande à notifier');
            }

            // Récupérer le client depuis la première commande
            $firstOrder = Order::with('user')->find($orders[0]['id']);
            if (!$firstOrder || !$firstOrder->user) {
                throw new Exception('Client introuvable');
            }

            $customer = $firstOrder->user;

            // Préparer les données pour l'email
            $ordersSummary = array_map(function($order) {
                $bdorder = Order::with(['user','vendor'])->find($order['id']);
                return [
                    'id' => $order['id'],
                    'order_number' => $order['order_number'],
                    'total_amount' => $order['total_amount'],
                    'name_store' => $bdorder && $bdorder->vendor ? $bdorder->vendor->name_store : ($order['vendor']['name_store'] ?? null),
                    'items_count' => count($order['items'] ?? []),
                    'order_url' => config('app.client_web_url') . '/commandes/' . $order['id']
                ];
            }, $orders);

            $totalAmount = array_sum(array_column($orders, 'total_amount'));

            $emailData = [
                'customer_name' => $customer->first_name . ' ' . $customer->last_name,
                'orders' => $ordersSummary,
                'orders_count' => count($orders),
                'total_amount' => $totalAmount,
                'payment_date' => now()->format('d/m/Y à H:i'),
                'orders_list_url' => config('app.client_web_url') . '/commandes',
                'support_email' => config('services.company.email', config('mail.from.address'))
            ];

            // Envoyer l'email
            Mail::to($customer->email)
                ->send(new ClientOrderConfirmationMail($emailData));

            Log::info('Email confirmation client envoyé', [
                'customer_email' => $customer->email,
                'orders_count' => count($orders),
                'total_amount' => $totalAmount
            ]);

            return [
                'success' => true,
                'message' => 'Email envoyé au client',
                'recipient' => $customer->email
            ];

        } catch (Exception $e) {
            Log::error('Erreur envoi email client', [
                'error' => $e->getMessage(),
                'orders' => array_column($orders, 'id')
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }


    /**
     * Envoyer l'email + bon de commande PDF au(x) vendeur(s)
     */
    public function sendVendorOrderNotifications(array $orders): array
    {
        $results = [];

        try {
            // Grouper les commandes par vendeur
            $ordersByVendor = [];
            foreach ($orders as $order) {
                $vendorId = $order['vendor']['id'];
                if (!isset($ordersByVendor[$vendorId])) {
                    $ordersByVendor[$vendorId] = [];
                }
                $ordersByVendor[$vendorId][] = $order;
            }

            // Envoyer un email par vendeur
            foreach ($ordersByVendor as $vendorId => $vendorOrders) {
                try {
                    $result = $this->sendSingleVendorNotification($vendorOrders);
                    $results[] = $result;
                } catch (Exception $e) {
                    Log::error('Erreur envoi email vendeur', [
                        'vendor_id' => $vendorId,
                        'error' => $e->getMessage()
                    ]);

                    $results[] = [
                        'success' => false,
                        'vendor_id' => $vendorId,
                        'error' => $e->getMessage()
                    ];
                }
            }

            return [
                'success' => true,
                'results' => $results,
                'vendors_notified' => count($results)
            ];

        } catch (Exception $e) {
            Log::error('Erreur envoi emails vendeurs', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }


    /**
     * Envoyer l'email + bon de commande à UN vendeur
     */
    private function sendSingleVendorNotification(array $vendorOrders): array
    {
        // Récupérer les infos complètes de la première commande
        $firstOrder = Order::with(['vendor.user', 'user'])->find($vendorOrders[0]['id']);
        
        if (!$firstOrder || !$firstOrder->vendor || !$firstOrder->vendor->user) {
            throw new Exception('Vendeur introuvable');
        }

        $vendor = $firstOrder->vendor;
        $vendorUser = $vendor->user;

        // Calculer les totaux
        $totalGross = array_sum(array_column($vendorOrders, 'total_amount'));
        $platformFeeRate = config('services.payment.platform_fee_rate', 0.07);
        $platformFee = $totalGross * $platformFeeRate;
        $netAmount = $totalGross - $platformFee;

        // Préparer les données pour l'email
        $ordersSummary = array_map(function($order) {
            return [
                'id' => $order['id'],
                'order_number' => $order['order_number'],
                'total_amount' => $order['total_amount'],
                'items_count' => count($order['items'] ?? []),
                'customer_name' => $order['customer_name'] ?? 'Client',
            ];
        }, $vendorOrders);

        $emailData = [
            'vendor_name' => $vendor->name_store ?? $vendor->name,
            'orders' => $ordersSummary,
            'orders_count' => count($vendorOrders),
            'total_gross' => $totalGross,
            'platform_fee' => $platformFee,
            'platform_fee_rate' => $platformFeeRate * 100,
            'net_amount' => $netAmount,
            'payment_date' => now()->format('d/m/Y à H:i'),
            'vendor_dashboard_url' => config('app.vendor_web_url', config('app.client_web_url')) . '/dashboard/commandes',
            'support_email' => config('services.company.email', config('mail.from.address'))
        ];

        // Générer le PDF du bon de commande (à la volée)
        $pdfContent = $this->generateOrderFormPDF($vendorOrders, $vendor, $emailData);
        
        // Créer un fichier temporaire
        $tempFile = tempnam(sys_get_temp_dir(), 'order_form_') . '.pdf';
        file_put_contents($tempFile, $pdfContent);

        try {
            // Envoyer l'email avec le PDF en pièce jointe
            Mail::to($vendorUser->email)
                ->send(new VendorOrderNotificationMail($emailData, $tempFile));

            Log::info('Email notification vendeur envoyé', [
                'vendor_id' => $vendor->id,
                'vendor_email' => $vendorUser->email,
                'orders_count' => count($vendorOrders),
                'net_amount' => $netAmount
            ]);

            return [
                'success' => true,
                'vendor_id' => $vendor->id,
                'vendor_email' => $vendorUser->email,
                'orders_count' => count($vendorOrders)
            ];

        } finally {
            // Nettoyer le fichier temporaire
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }


    /**
     * Générer le PDF du bon de commande pour le vendeur
     */
    private function generateOrderFormPDF(array $orders, $vendor, array $emailData): string
    {
        // Préparer les données pour le template
        $orderFormData = [
            'form_number' => 'BC-' . now()->format('Ymd') . '-' . $vendor->id,
            'date' => now()->format('d/m/Y'),
            'vendor' => [
                'name' => $vendor->name_store ?? $vendor->name,
                'email' => $vendor->user->email ?? 'N/A',
                'address' => $this->formatVendorAddress($vendor)
            ],
            'orders' => array_map(function($order) use ($vendor) {
                $fullOrder = Order::with(['user', 'items.product', 'items.productVariant'])
                    ->find($order['id']);
                
                return [
                    'order_number' => $fullOrder->order_number,
                    'date' => $fullOrder->created_at->format('d/m/Y H:i'),
                    'customer' => [
                        'name' => $fullOrder->user->first_name . ' ' . $fullOrder->user->last_name,
                        'email' => $fullOrder->user->email,
                        'phone' => $fullOrder->user->phone ?? 'N/A'
                    ],
                    'items' => $fullOrder->items->map(function($item) {
                        return [
                            'name' => $item->product->name ?? 'Produit supprimé',
                            'variant' => $this->formatVariant($item->productVariant),
                            'sku' => $item->productVariant->sku ?? null,
                            'quantity' => $item->quantity,
                            'unit_price' => number_format($item->unit_price, 2, ',', ' ') . ' €',
                            'total' => number_format($item->unit_price * $item->quantity, 2, ',', ' ') . ' €'
                        ];
                    })->toArray(),
                    'total' => number_format($fullOrder->total_amount, 2, ',', ' ') . ' €'
                ];
            }, $orders),
            'summary' => [
                'total_gross' => number_format($emailData['total_gross'], 2, ',', ' ') . ' €',
                'platform_fee_rate' => $emailData['platform_fee_rate'],
                'platform_fee' => number_format($emailData['platform_fee'], 2, ',', ' ') . ' €',
                'net_amount' => number_format($emailData['net_amount'], 2, ',', ' ') . ' €'
            ],
            'company' => [
                'name' => config('app.name'),
                'address' => config('services.company.address', '123 Rue Example, 75000 Paris'),
                'phone' => config('services.company.phone', '+33 1 23 45 67 89'),
                'email' => config('services.company.email', 'contact@monsite.com')
            ]
        ];

        // Générer le PDF
        $pdf = Pdf::loadView('emails.vendor-order-form', $orderFormData);
        
        $pdf->setPaper('A4', 'portrait');
        $pdf->setOptions([
            'dpi' => 96,
            'defaultFont' => 'DejaVu Sans'
        ]);

        return $pdf->output();
    }

    /**
     * Formater l'adresse du vendeur
     */
    private function formatVendorAddress($vendor): ?string
    {
        if (!$vendor) return null;

        $street = trim($vendor->rue ?? '');
        $postalCity = trim(($vendor->code_postal ?? '') . ' ' . ($vendor->ville ?? ''));
        
        $parts = array_filter([$street, $postalCity]);
        
        return count($parts) ? implode(', ', $parts) . ', France' : null;
    }

    /**
     * Formater les informations de variante
     */
    private function formatVariant($variant): ?string
    {
        if (!$variant) return null;

        $parts = array_filter([
            $variant->size ? "Taille: {$variant->size}" : null,
            $variant->color ? "Couleur: {$variant->color}" : null,
        ]);

        return $parts ? implode(', ', $parts) : null;
    }
}