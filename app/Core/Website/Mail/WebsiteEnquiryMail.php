<?php

declare(strict_types=1);

namespace App\Core\Website\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A message from the website's contact form, to the company's own inbox; the
 * message is also kept in the Super Admin panel (Website > Messages).
 * Platform mail: no workspace is involved. Encrypted on the queue, because
 * it carries a visitor's details. Replying answers the visitor.
 */
final class WebsiteEnquiryMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** Seconds; equal to the Horizon supervisor's worker timeout for this queue. */
    public int $timeout = 60;

    /** @var list<int> Seconds between attempts. */
    public array $backoff = [30, 120];

    /**
     * @param  array{name: string, email: string, phone: ?string, company: ?string, topic: string, message: string, locale: string}  $enquiry
     */
    public function __construct(public readonly array $enquiry)
    {
        $this->onQueue('notifications');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->enquiry['email'], $this->enquiry['name'])],
            subject: 'Website enquiry: '.$this->enquiry['topic'].' - '.$this->enquiry['name'],
        );
    }

    public function content(): Content
    {
        // Plain views, not Markdown: the visitor's words must arrive as text, never as links or formatting.
        return new Content(view: 'mail.website.enquiry', text: 'mail.website.enquiry-text', with: ['enquiry' => $this->enquiry]);
    }
}
