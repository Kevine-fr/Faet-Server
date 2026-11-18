<?php

namespace App\Services;

use App\Mail\InvoiceMail;
use App\Mail\VendorInvoiceCopyMail;
use App\Models\Invoice;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf as FacadePdf;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\PaymentIntent;

class InvoiceService
{
    private $securePaymentService;
    private $cloudinaryService;

    public function __construct(SecurePaymentService $securePaymentService, CloudinaryService $cloudinaryService)
    {
        $this->securePaymentService = $securePaymentService;
        $this->cloudinaryService = $cloudinaryService;
    }

     /**
     * Générer une facture PDF après paiement réussi
     */
    public function generateInvoiceAfterPayment($orderId, $paymentIntentId = null, $captureId = null)
    {
        try {
            $order = Order::with([
                'user',
                'items.product',
                'items.productVariant',
                'vendor'
            ])->findOrFail($orderId);

            // Récupérer les infos de paiement selon la méthode
            $paymentInfo = $this->getPaymentMethodDetails($order, $paymentIntentId, $captureId);
            Log::info("INFO PAYMENT", $paymentInfo);

            // Générer la facture
            $invoiceData = $this->prepareInvoiceData($order, $paymentInfo);
            Log::info("INVOICE DATA", $invoiceData);
            $invoiceNumber = $this->generateInvoiceNumber($order);

            // Générer le PDF en mémoire
            $pdfContent = $this->generatePDFContent($invoiceData);

            // Upload vers Cloudinary
            $fileName = "facture-{$invoiceNumber}.pdf";
            $cloudinaryResult = $this->cloudinaryService->uploadPDFFromContent(
                $pdfContent,
                $fileName,
                [
                    'folder' => config('cloudinary.folders.invoices') . '/' . now()->year . '/' . now()->month,
                    'public_id' => "invoice-{$orderId}-{$invoiceNumber}",
                    'tags' => ['invoice', 'order-' . $orderId, 'user-' . $order->user_id]
                ]
            );

            if (!$cloudinaryResult['success']) {
                throw new Exception('Erreur lors de l\'upload sur Cloudinary: ' . $cloudinaryResult['error']);
            }

            Log::info('Upload Cloudinary réussi', [
                'public_id' => $cloudinaryResult['public_id'],
                'secure_url' => $cloudinaryResult['secure_url'],
                'bytes' => $cloudinaryResult['bytes']
            ]);

            // Sauvegarder en base
            $invoice = Invoice::create([
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'vendor_id' => $order->vendor_id,
                'invoice_number' => $invoiceNumber,
                'cloudinary_public_id' => $cloudinaryResult['public_id'],
                'cloudinary_url' => $cloudinaryResult['secure_url'],
                'file_size' => $cloudinaryResult['bytes'],
                'total_amount' => $order->total_amount,
                'status' => 'generated',
                'generated_at' => now()
            ]);

            $this->securePaymentService->secureLog('info', 'Facture générée et uploadée', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoiceNumber,
                'cloudinary_public_id' => $cloudinaryResult['public_id'],
                'file_size' => $cloudinaryResult['bytes']
            ]);

            // Générer l'URL de téléchargement de manière sûre
            $downloadUrl = null;
            try {
                $downloadUrl = $this->cloudinaryService->generateSecureDownloadUrl($cloudinaryResult['public_id'], 24);
            } catch (Exception $e) {
                Log::warning('Impossible de générer URL sécurisée, utilisation URL directe', [
                    'error' => $e->getMessage()
                ]);
                $downloadUrl = $cloudinaryResult['secure_url'];
            }

            return [
                'success' => true,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoiceNumber,
                'cloudinary_public_id' => $cloudinaryResult['public_id'],
                'download_url' => route('invoice.download', $invoice->id),
                'direct_url' => $cloudinaryResult['secure_url'],
                'secure_download_url' => $downloadUrl,
                'file_size' => $cloudinaryResult['bytes']
            ];

        } catch (Exception $e) {
            $this->securePaymentService->secureLog('error', 'Erreur génération facture', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Générer le contenu PDF en mémoire
    */
    private function generatePDFContent(array $invoiceData): string
    {
        $pdf = FacadePdf::loadView('invoices.template', $invoiceData);
        
        // Configuration PDF
        $pdf->setPaper('A4', 'portrait');
        $pdf->setOptions([
            'dpi' => 96,
            'defaultFont' => 'DejaVu Sans',
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true
        ]);

        return $pdf->output();
    }

    /**
     * Télécharger une facture via une URL sécurisée temporaire
    */
    public function downloadInvoice($invoiceId)
    {
        $user = auth()->user();
        
        $invoice = Invoice::where('id', $invoiceId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if (!$invoice->cloudinary_public_id) {
            abort(404, 'Facture introuvable sur le stockage');
        }

        // Vérifier que le fichier existe sur Cloudinary
        if (!$this->cloudinaryService->fileExists($invoice->cloudinary_public_id)) {
            abort(404, 'Fichier de facture non trouvé');
        }

        // Générer une URL de téléchargement sécurisée qui expire dans 1 heure
        $secureUrl = $this->cloudinaryService->generateSecureDownloadUrl($invoice->cloudinary_public_id, 1);

        // Rediriger vers l'URL Cloudinary sécurisée
        return redirect($secureUrl);
    }

    /**
     * Obtenir l'URL de téléchargement direct (pour affichage dans le frontend)
     */
    public function getDownloadUrl($invoiceId): ?string
    {
        $user = auth()->user();
        
        $invoice = Invoice::where('id', $invoiceId)
            ->where('user_id', $user->id)
            ->first();

        if (!$invoice || !$invoice->cloudinary_public_id) {
            return null;
        }

        // URL qui passe par notre contrôleur pour vérification des droits
        return route('invoice.download', $invoice->id);
    }

    /**
     * Obtenir l'URL directe Cloudinary (pour emails par exemple)
     */
    public function getDirectUrl($invoiceId): ?string
    {
        $invoice = Invoice::find($invoiceId);
        
        if (!$invoice || !$invoice->cloudinary_public_id) {
            return null;
        }

        return $this->cloudinaryService->getPublicUrl($invoice->cloudinary_public_id);
    }

    /**
     * Envoyer la facture par email avec le PDF en pièce jointe
     */
    public function emailInvoice($invoiceId)
    {
        $invoice = Invoice::with(['order.user', 'order.vendor.user'])->findOrFail($invoiceId);

        Log::info('🧑‍💻🧑‍💻🧑‍💻🧑‍💻🧑‍💻🧑‍💻🧑‍💻🧑‍💻🧑‍💻🧑‍💻🧑‍💻🧑‍💻🧑‍💻🧑‍💻🧑‍💻🧑‍💻🧑‍💻🧑‍💻🧑‍💻🧑‍💻Tentative d\'envoi de facture par email', [
            'invoice_id' => $invoice->id,
            'user_email' => $invoice->order->user->email,
            'vendor_email' => $invoice->order->vendor->user->email
        ]);
        
        if (!$invoice->cloudinary_public_id) {
            throw new Exception('Facture non trouvée sur le stockage');
        }

        // ✅ CORRECTION : Générer une URL sécurisée au lieu d'une URL publique
        $pdfUrl = $this->cloudinaryService->generateSecureDownloadUrl(
            $invoice->cloudinary_public_id,
            1 // Expire dans 1 heure
        );

        // ✅ Vérifier que l'URL est valide
        if (empty($pdfUrl)) {
            throw new Exception('Impossible de générer l\'URL de téléchargement Cloudinary');
        }

        // Télécharger le PDF
        $pdfContent = @file_get_contents($pdfUrl);

        if ($pdfContent === false) {
            // ✅ FALLBACK : Générer le PDF à la volée si le téléchargement échoue
            Log::warning('Téléchargement Cloudinary échoué, régénération du PDF', [
                'invoice_id' => $invoiceId,
                'cloudinary_url' => $pdfUrl
            ]);

            // Récupérer les données de la commande
            $order = $invoice->order->load(['items.product', 'items.productVariant', 'vendor', 'user']);
            $paymentInfo = $this->getPaymentMethodDetails($order, null, null);
            $invoiceData = $this->prepareInvoiceData($order, $paymentInfo);

            // Régénérer le PDF
            $pdfContent = $this->generatePDFContent($invoiceData);
        }

        // Créer un fichier temporaire pour l'email
        $tempFile = tempnam(sys_get_temp_dir(), 'invoice_email_') . '.pdf';
        file_put_contents($tempFile, $pdfContent);

        try {
            // Envoyer au client
            Mail::to($invoice->order->user->email)
                ->send(new InvoiceMail($invoice, $tempFile));

            // Envoyer au vendeur une copie
            Mail::to($invoice->order->vendor->user->email)
                ->send(new VendorInvoiceCopyMail($invoice, $tempFile));

            $this->securePaymentService->secureLog('info', 'Facture envoyée par email', [
                'invoice_id' => $invoice->id,
                'recipient_client' => $invoice->order->user->email,
                'recipient_vendor' => $invoice->order->vendor->user->email
            ]);

            return [
                'success' => true,
                'message' => 'Facture envoyée par email'
            ];

        } finally {
            // Nettoyer le fichier temporaire
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Supprimer une facture (admin uniquement)
     */
    public function deleteInvoice($invoiceId): array
    {
        try {
            $invoice = Invoice::findOrFail($invoiceId);
            
            // Supprimer de Cloudinary
            if ($invoice->cloudinary_public_id) {
                $deleteResult = $this->cloudinaryService->deleteFile($invoice->cloudinary_public_id);
                
                if (!$deleteResult['success']) {
                    $this->securePaymentService->secureLog('warning', 'Erreur suppression Cloudinary', [
                        'invoice_id' => $invoiceId,
                        'public_id' => $invoice->cloudinary_public_id,
                        'error' => $deleteResult['error']
                    ]);
                }
            }
            
            // Supprimer de la base
            $invoice->delete();

            return [
                'success' => true,
                'message' => 'Facture supprimée avec succès'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Régénérer une facture existante
     */
    public function regenerateInvoice($invoiceId): array
    {
        try {
            $invoice = Invoice::with('order')->findOrFail($invoiceId);
            
            // Supprimer l'ancienne version de Cloudinary
            if ($invoice->cloudinary_public_id) {
                $this->cloudinaryService->deleteFile($invoice->cloudinary_public_id);
            }
            
            // Régénérer
            $result = $this->generateInvoiceAfterPayment(
                $invoice->order_id,
                $invoice->order->payment_method === 'stripe' ? $invoice->order->transactions->first()?->external_transaction_id : null,
                $invoice->order->payment_method === 'paypal' ? $invoice->order->transactions->first()?->external_transaction_id : null
            );
            
            if ($result['success']) {
                // Mettre à jour les infos Cloudinary
                $invoice->update([
                    'cloudinary_public_id' => $result['cloudinary_public_id'],
                    'cloudinary_url' => $result['direct_url'],
                    'file_size' => $result['file_size'],
                    'generated_at' => now()
                ]);
                
                return [
                    'success' => true,
                    'message' => 'Facture régénérée avec succès',
                    'download_url' => route('invoice.download', $invoice->id),
                    'direct_url' => $result['direct_url']
                ];
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Obtenir les statistiques des factures
     */
    public function getInvoiceStats(): array
    {
        $totalInvoices = Invoice::count();
        $totalSize = Invoice::sum('file_size');
        $thisMonthCount = Invoice::whereMonth('created_at', now()->month)->count();
        
        // Utilisation Cloudinary
        $cloudinaryUsage = $this->cloudinaryService->getStorageUsage();
        
        return [
            'total_invoices' => $totalInvoices,
            'total_size_bytes' => $totalSize,
            'total_size_mb' => round($totalSize / 1024 / 1024, 2),
            'this_month_count' => $thisMonthCount,
            'cloudinary_usage' => $cloudinaryUsage['success'] ? $cloudinaryUsage['usage'] : null
        ];
    }

    // Les autres méthodes restent identiques...
    private function getPaymentMethodDetails($order, $paymentIntentId = null, $captureId = null)
    {
        // Code identique à la version précédente
        if ($order->payment_method === 'stripe' && $paymentIntentId) {
            return $this->getStripePaymentDetails($paymentIntentId);
        } elseif ($order->payment_method === 'paypal' && $captureId) {
            return $this->getPayPalPaymentDetails($captureId);
        }

        return [
            'method' => $order->payment_method,
            'display' => ucfirst($order->payment_method)
        ];
    }

    private function prepareInvoiceData($order, $paymentInfo)
    {
        // Assure que les relations utiles sont chargées pour éviter les N+1
        $order->loadMissing(['user', 'vendor', 'items.product', 'items.productVariant']);

        // Formatter simple pour les montants (2 décimales)
        $fmt = fn($n) => number_format((float)($n ?? 0), 2, '.', '');

        $items = $order->items->map(function ($item) use ($fmt) {
            $productName = $item->product_name ?? optional($item->product)->name ?? 'Produit supprimé';
            $variantLabel = method_exists($this, 'formatVariant')
                ? $this->formatVariant(optional($item->productVariant))
                : optional($item->productVariant)->name ?? null;

            $quantity = (int) ($item->quantity ?? 0);
            $unitPrice = (float) ($item->unit_price ?? 0);
            $lineTotal = round($quantity * $unitPrice, 2);

            return [
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'variant_id' => $item->product_variant_id,
                'name' => $productName,
                'sku' => $item->sku ?? null,
                'variant' => $variantLabel,
                'quantity' => $quantity,
                'unit_price' => $fmt($unitPrice),
                'total' => $fmt($lineTotal),
            ];
        })->values()->toArray();

        // Totaux (calculs sûrs)
        $subtotal = (float) $order->items->sum(fn($i) => ($i->quantity ?? 0) * ($i->unit_price ?? 0));
        $shipping = (float) ($order->shipping_cost ?? 0);
        $tax = (float) ($order->tax_amount ?? 0);
        $discount = (float) ($order->discount_amount ?? 0);
        $total = (float) ($order->total_amount ?? ($subtotal + $shipping + $tax - $discount));

        $vendorModel = $order->vendor;
        $vendorAddress = null;

        if ($vendorModel) {
            $street = trim($vendorModel->rue ?? '');
            $cityPart = trim(($vendorModel->code_postal ?? '') . ' ' . ($vendorModel->ville ?? ''));
            $parts = array_filter([$street, $cityPart]); // retire les éléments vides
            $vendorAddress = count($parts) ? implode(', ', $parts) : null;
        }

        // Code identique à la version précédente
        return [
            'invoice_number' => $this->generateInvoiceNumber($order),
            'date' => now()->format('d/m/Y'),
            'order' => [
                'id' => $order->id,
                'number' => $order->order_number,
                'date' => optional($order->created_at)->format('d/m/Y H:i'),
                'date_iso' => optional($order->created_at)->toIso8601String(),
                'status' => $order->status,
                'currency' => $order->currency ?? config('app.currency', 'EUR'),
            ],
            'customer' => [
                'name' => trim((optional($order->user)->first_name ?? '') . ' ' . (optional($order->user)->last_name ?? '')),
                'email' => optional($order->user)->email,
                'address' => $order->shipping_address ?? $order->billing_address ?? (optional($order->user)->street ? optional($order->user)->street . ', ' . optional($order->user)->city : null),
            ],
            'vendor' => [
                'name' => optional($order->vendor)->name_store ?? optional($order->vendor)->name,
                'email' => optional($order->vendor)->email,
                'address' => $vendorAddress
            ],
            'items' => $items,
            'totals' => [
                'subtotal' => $fmt($subtotal),
                'shipping' => $fmt($shipping),
                'tax' => $fmt($tax),
                'discount' => $fmt($discount),
                'total' => $fmt($total),
            ],
            'payment' => array_merge($paymentInfo, [
                'status' => 'Payé',
                'date' => $order->updated_at->format('d/m/Y H:i')
            ]),
            'company' => [
                'name' => config('app.name'),
                'address' => config('services.company.address'),
                'phone' => config('services.company.phone'),
                'email' => config('services.company.email'),
                'website' => config('app.client_web_url')
            ]
        ];
    }

    private function formatVariant($variant)
    {
        if (!$variant) return '';

        $parts = array_filter([
            $variant->size ? "Taille: {$variant->size}" : null,
            $variant->color ? "Couleur: {$variant->color}" : null,
            $variant->sku ? "Réf: {$variant->sku}" : null
        ]);

        return implode(', ', $parts);
    }

    private function generateInvoiceNumber($order)
    {
        $year = now()->year;
        $month = now()->format('m');
        
        $count = Invoice::whereYear('created_at', $year)
            ->whereMonth('created_at', now()->month)
            ->count() + 1;

        return "INV-{$year}{$month}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    // Méthodes de récupération des détails de paiement (identiques)
    private function getStripePaymentDetails($paymentIntentId)
    {
        try {
            $paymentIntent = \Stripe\PaymentIntent::retrieve([
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
                    'exp_month' => $card->exp_month,
                    'exp_year' => $card->exp_year,
                    'formatted' => ucfirst($card->brand) . ' •••• ' . $card->last4
                ];
            }

            return [
                'method' => 'stripe',
                'display' => 'Stripe'
            ];

        } catch (Exception $e) {
            return [
                'method' => 'stripe',
                'display' => 'Carte bancaire',
                'error' => 'Détails non disponibles'
            ];
        }
    }

    private function getPayPalPaymentDetails($captureId)
    {
        try {
            // Utiliser le service PayPal pour récupérer les détails
            $paypalService = app(PayPalPaymentService::class);
            $token = $paypalService->getAccessToken();

            $response = Http::timeout(30)
                ->withToken($token)
                ->get($paypalService->getBaseUrl() . "/v2/payments/captures/{$captureId}");

            if ($response->successful()) {
                $capture = $response->json();
                $payer = $capture['payer'] ?? [];

                return [
                    'method' => 'paypal',
                    'type' => 'paypal',
                    'display' => 'PayPal',
                    'payer_email' => $payer['email_address'] ?? null,
                    'payer_name' => ($payer['name']['given_name'] ?? '') . ' ' . ($payer['name']['surname'] ?? ''),
                    'formatted' => 'PayPal (' . ($payer['email_address'] ?? 'Compte PayPal') . ')'
                ];
            }

            return [
                'method' => 'paypal',
                'display' => 'PayPal',
                'formatted' => 'PayPal'
            ];

        } catch (Exception $e) {
            return [
                'method' => 'paypal',
                'display' => 'PayPal',
                'formatted' => 'PayPal',
                'error' => 'Détails non disponibles'
            ];
        }
    }
}