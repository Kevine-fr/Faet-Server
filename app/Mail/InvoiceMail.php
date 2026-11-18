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

class InvoiceMail extends Mailable
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
            subject: 'Votre facture ' . $this->invoice->invoice_number . ' - ' . config('app.name'),
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
            view: 'emails.invoice',
            with: [
                'invoice' => $this->invoice,
                'invoiceNumber' => $this->invoice->invoice_number,
                'orderNumber' => $this->invoice->order->order_number,
                'customerName' => $this->invoice->order->user->first_name . ' ' . $this->invoice->order->user->last_name,
                'amount' => $this->invoice->total_amount,
                'formattedAmount' => number_format($this->invoice->total_amount, 2, ',', ' ') . ' €',
                'companyName' => config('app.name'),
                'supportEmail' => config('services.company.email', config('mail.from.address'))
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
