<?php

declare(strict_types=1);

namespace App\Modules\Support\Mail;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Support\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use SensitiveParameter;

/**
 * Asks the requester to rate the support they received. The single-use link carries the plain token, so
 * the queued payload is encrypted and is only queued once the transaction that issued it has committed.
 */
final class CsatInvitationMail extends Mailable implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** Seconds; equal to the Horizon supervisor's worker timeout for this queue. */
    public int $timeout = 60;

    /** @var list<int> Seconds between attempts. */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly string $ticketId,
        public readonly string $number,
        #[SensitiveParameter]
        private readonly string $token,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        // No database access here: the number travels with the message, so an envelope can be inspected anywhere.
        return new Envelope(subject: __('How did we do? :number', ['number' => $this->number]));
    }

    public function content(): Content
    {
        $ticket = $this->ticket();

        return new Content(
            markdown: 'mail.support.csat',
            with: [
                'workspace' => app(TenantContext::class)->require()->name,
                'name' => $ticket->displayRequester(),
                'number' => $ticket->number,
                'subject' => $ticket->subject,
                'url' => route('support.csat.show', ['token' => $this->token]),
            ],
        );
    }

    private function ticket(): Ticket
    {
        return Ticket::query()->with(['contact', 'company'])->findOrFail($this->ticketId);
    }
}
