<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

class VendorInvoiceCopyMail extends Mailable
{
    use Queueable, SerializesModels;

    public $invoice;
    private $pdfPath;

    /**
     * Create a new message instance.
     */
    public function __construct(Invoice $invoice, string $pdfPath)
    {
        $this->invoice = $invoice;
        $this->pdfPath = $pdfPath;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Copie facture ' . $this->invoice->invoice_number . ' - Vente sur ' . config('app.name'),
            from: config('mail.from.address'),
            replyTo: [config('services.company.email', config('mail.from.address'))]
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.vendor-invoice-copy',
            with: [
                'invoice' => $this->invoice,
                'invoiceNumber' => $this->invoice->invoice_number,
                'orderNumber' => $this->invoice->order->order_number,
                'vendorName' => $this->invoice->vendor->name,
                'customerName' => $this->invoice->order->user->first_name . ' ' . $this->invoice->order->user->last_name,
                'amount' => $this->invoice->total_amount,
                'formattedAmount' => number_format($this->invoice->total_amount, 2, ',', ' ') . ' €',
                'platformName' => config('app.name'),
                'supportEmail' => config('services.company.email', config('mail.from.address')),
                // Calculer les montants vendeur (si tu as la logique des frais)
                'platformFeeRate' => config('services.payment.platform_fee_rate', 0.07),
                'platformFee' => $this->invoice->total_amount * config('services.payment.platform_fee_rate', 0.07),
                'vendorAmount' => $this->invoice->total_amount * (1 - config('services.payment.platform_fee_rate', 0.07))
            ]
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->pdfPath)
                ->as('facture-' . $this->invoice->invoice_number . '.pdf')
                ->withMime('application/pdf')
        ];
    }
}