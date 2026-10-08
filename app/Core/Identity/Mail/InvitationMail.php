<?php

declare(strict_types=1);

namespace App\Core\Identity\Mail;

use App\Core\Identity\Models\Invitation;
use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Core\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use SensitiveParameter;

/**
 * Queued, so it runs in the tenant context carried by the queue payload, and
 * encrypted, because the payload holds the one-time invitation token.
 */
final class InvitationMail extends Mailable implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Invitation $invitation,
        #[SensitiveParameter]
        private readonly string $token,
    ) {
        $this->onQueue('notifications');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('You have been invited to :workspace', [
                'workspace' => app(TenantContext::class)->require()->name,
            ]),
        );
    }

    public function content(): Content
    {
        $this->invitation->loadMissing(['role', 'inviter']);
        $tenant = app(TenantContext::class)->require();

        return new Content(
            markdown: 'mail.invitation',
            with: [
                'workspace' => $tenant->name,
                'inviter' => $this->invitation->inviter?->name,
                'role' => $this->invitation->role->name,
                'url' => route('auth.invitations.show', ['token' => $this->token]),
                // The day as the workspace's clock has it; the view writes it in the mail's language.
                'expiresAt' => $this->invitation->expires_at->copy()->timezone($tenant->timezone),
            ],
        );
    }
}
