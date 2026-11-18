<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VendorOrderNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $emailData;
    private $pdfPath;

    /**
     * Create a new message instance.
     */
    public function __construct(array $emailData, string $pdfPath)
    {
        $this->emailData = $emailData;
        $this->pdfPath = $pdfPath;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $ordersCount = $this->emailData['orders_count'];
        $subject = $ordersCount > 1 
            ? "🎉 Nouvelles ventes ({$ordersCount}) - " . config('app.name')
            : "🎉 Nouvelle vente - " . config('app.name');

        return new Envelope(
            subject: $subject,
            from: config('mail.from.address'),
            replyTo: [$this->emailData['support_email']]
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.vendor-order-notification',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->pdfPath)
                ->as('bon-commande-' . now()->format('Ymd') . '.pdf')
                ->withMime('application/pdf')
        ];
    }
}
