<?php

namespace App\Services;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Support\Facades\Log;

class InvoiceGeneratorService
{
    /**
     * Générer le PDF de facture à la volée (sans stockage)
     */
    public function generateInvoicePDF(Order $order): string
    {
        try {
            // Préparer les données pour le template
            $invoiceData = $this->prepareInvoiceData($order);

            Log::info('Données facture préparées', [
                'order_id' => $order->id,
                'invoice_number' => $invoiceData['invoice_number']
            ]);

            // Générer le PDF
            $pdf = Pdf::loadView('invoices.template', $invoiceData);
            
            // Configuration PDF
            $pdf->setPaper('A4', 'portrait');
            $pdf->setOptions([
                'dpi' => 96,
                'defaultFont' => 'DejaVu Sans',
                'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true
            ]);

            return $pdf->output();

        } catch (Exception $e) {
            Log::error('Erreur génération PDF facture', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Préparer les données pour le template de facture
     */
    private function prepareInvoiceData(Order $order): array
    {
        // Formatter pour les montants
        $fmt = fn($n) => number_format((float)($n ?? 0), 2, ',', ' ') . ' €';

        // Préparer les items
        $items = $order->items->map(function ($item) use ($fmt) {
            $productName = $item->product->name ?? 'Produit supprimé';
            $variantLabel = $this->formatVariant($item->productVariant);

            $quantity = (int) ($item->quantity ?? 0);
            $unitPrice = (float) ($item->unit_price ?? 0);
            $lineTotal = round($quantity * $unitPrice, 2);

            return [
                'name' => $productName,
                'variant' => $variantLabel,
                'quantity' => $quantity,
                'unit_price' => $fmt($unitPrice),
                'total' => $fmt($lineTotal),
                'sku' => $item->productVariant->sku ?? null,
            ];
        })->values()->toArray();

        // Calcul des totaux
        $subtotal = (float) $order->subtotal;
        $discount = (float) ($order->discount_amount ?? 0);
        $total = (float) $order->total_amount;

        // Informations vendeur
        $vendorModel = $order->vendor;
        $vendorAddress = $this->formatVendorAddress($vendorModel);

        // Informations client
        $customerName = trim(($order->user->first_name ?? '') . ' ' . ($order->user->last_name ?? ''));
        
        return [
            'invoice_number' => $this->generateInvoiceNumber($order),
            'date' => now()->format('d/m/Y'),
            
            'order' => [
                'number' => $order->order_number,
                'date' => $order->created_at->format('d/m/Y H:i'),
                'status' => $order->status,
            ],
            
            'customer' => [
                'name' => $customerName,
                'email' => $order->user->email ?? 'N/A',
            ],
            
            'vendor' => [
                'name' => $vendorModel->name_store ?? $vendorModel->name ?? 'Vendeur',
                'email' => $vendorModel->user->email ?? null,
                'address' => $vendorAddress,
            ],
            
            'items' => $items,
            
            'totals' => [
                'subtotal' => $fmt($subtotal),
                'discount' => $discount > 0 ? $fmt($discount) : null,
                'total' => $fmt($total),
            ],
            
            'promo_code' => $order->promoCode ? [
                'code' => $order->promoCode->code,
                'name' => $order->promoCode->name,
            ] : null,
            
            'payment' => [
                'method' => $this->formatPaymentMethod($order->payment_method),
                'status' => 'Payé',
                'date' => $order->updated_at->format('d/m/Y H:i')
            ],
            
            'company' => [
                'name' => config('app.name'),
                'address' => config('services.company.address', '123 Rue Example, 75000 Paris'),
                'phone' => config('services.company.phone', '+33 07 66 33 91 40'),
                'email' => config('services.company.email', 'faet@byfaet.fr'),
                'website' => config('app.client_web_url', 'https://www.byfaet.fr')
            ]
        ];
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
     * Formater la méthode de paiement
     */
    private function formatPaymentMethod(?string $method): string
    {
        return match($method) {
            'stripe' => 'Carte bancaire',
            'paypal' => 'PayPal',
            default => 'Paiement en ligne'
        };
    }

    /**
     * Générer un numéro de facture unique
     */
    private function generateInvoiceNumber(Order $order): string
    {
        $year = $order->created_at->year;
        $month = $order->created_at->format('m');
        
        // Utiliser l'ID de commande comme numéro unique
        return "INV-{$year}{$month}-" . str_pad($order->id, 4, '0', STR_PAD_LEFT);
    }
}