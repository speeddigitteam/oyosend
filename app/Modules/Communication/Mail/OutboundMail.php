<?php

declare(strict_types=1);

namespace App\Modules\Communication\Mail;

use App\Modules\Communication\Channels\Data\OutboundPayload;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * One outbox message as an email. Sent from inside the queued outbox job, so it is itself not queued: the job
 * owns retries, status and the result.
 *
 * The body is plain text rendered through an escaping view. Tenant text is never compiled as a template.
 */
final class OutboundMail extends Mailable
{
    public function __construct(private readonly OutboundPayload $payload) {}

    public function envelope(): Envelope
    {
        $from = (string) config('mail.from.address');
        $name = $this->payload->fromName !== null && $this->payload->fromName !== ''
            ? $this->payload->fromName
            : (string) config('mail.from.name');

        return new Envelope(
            from: new Address($from, $name),
            replyTo: $this->payload->replyTo === null ? [] : [new Address($this->payload->replyTo)],
            subject: $this->singleLine((string) $this->payload->subject),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.communication.message',
            with: [
                'body' => $this->payload->body,
                'footer' => $this->payload->footer,
                'unsubscribeUrl' => $this->payload->unsubscribeUrl,
            ],
        );
    }

    public function headers(): Headers
    {
        $text = [];

        if ($this->payload->unsubscribeUrl !== null) {
            $text['List-Unsubscribe'] = '<'.$this->payload->unsubscribeUrl.'>';
            $text['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        return new Headers(text: $text);
    }

    /** A subject never carries a line break: that is how header injection starts. */
    private function singleLine(string $value): string
    {
        return trim((string) preg_replace('/[\r\n\t]+/', ' ', $value));
    }
}
