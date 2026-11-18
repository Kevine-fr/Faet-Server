<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\invoices;
use App\Models\Order;
use App\Services\InvoiceGeneratorService;
use App\Services\InvoiceService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InvoicesController extends Controller
{
    private $invoiceGenerator;

    public function __construct(InvoiceGeneratorService $invoiceGenerator)
    {
        $this->invoiceGenerator = $invoiceGenerator;
    }

    /**
     * Télécharger la facture au format PDF (génération à la volée)
     */
    public function downloadInvoice(Request $request, $orderId)
    {
        try {
            $user = auth()->user();
            
            // Récupérer la commande avec vérification des droits
            $order = Order::with([
                'user',
                'items.product.images',
                'items.productVariant',
                'vendor',
                'promoCode'
            ])
            ->where('id', $orderId)
            ->where('user_id', $user->id)
            ->where('payment_status', 'complete') // Seulement les commandes payées
            ->firstOrFail();

            Log::info('Génération facture demandée', [
                'order_id' => $orderId,
                'user_id' => $user->id,
                'order_number' => $order->order_number
            ]);

            // Générer le PDF à la volée
            $pdfContent = $this->invoiceGenerator->generateInvoicePDF($order);
            $fileName = "facture-{$order->order_number}.pdf";

            // Retourner le PDF en download
            return response($pdfContent, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', "attachment; filename=\"{$fileName}\"")
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');

        } catch (Exception $e) {
            Log::error('Erreur génération facture', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Impossible de générer la facture'
            ], 500);
        }
    }

    /**
     * Prévisualiser la facture dans le navigateur (inline)
     */
    public function previewInvoice(Request $request, $orderId)
    {
        try {
            $user = auth()->user();
            
            $order = Order::with([
                'user',
                'items.product.images',
                'items.productVariant',
                'vendor',
                'promoCode'
            ])
            ->where('id', $orderId)
            ->where('user_id', $user->id)
            ->where('payment_status', 'complete')
            ->firstOrFail();

            // Générer le PDF
            $pdfContent = $this->invoiceGenerator->generateInvoicePDF($order);
            $fileName = "facture-{$order->order_number}.pdf";

            // Retourner en inline pour affichage navigateur
            return response($pdfContent, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', "inline; filename=\"{$fileName}\"")
                ->header('Cache-Control', 'public, max-age=3600'); // Cache 1h

        } catch (Exception $e) {
            Log::error('Erreur prévisualisation facture', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Impossible de prévisualiser la facture'
            ], 500);
        }
    }

    
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(invoices $invoices)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(invoices $invoices)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, invoices $invoices)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(invoices $invoices)
    {
        //
    }
}
