<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Mail;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\EmailMarketing\Actions\PublicSignup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use SensitiveParameter;

/**
 * The one-time double opt-in confirmation of a public sign-up form. It is a reply to something the recipient
 * just asked for, not marketing: it goes out through Laravel's mailer like the support satisfaction invitation,
 * because the address has no consent yet and the platform outbox refuses marketing mail to such an address.
 * The link carries the plain token, so the queued payload is encrypted and queued after commit.
 */
final class SignupConfirmationMail extends Mailable implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** Seconds; equal to the Horizon supervisor's worker timeout for this queue. */
    public int $timeout = 60;

    /** @var list<int> Seconds between attempts. */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly string $listName,
        #[SensitiveParameter]
        private readonly string $token,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Confirm your subscription to :list', ['list' => $this->listName]));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'email_marketing::mail.signup',
            with: [
                'workspace' => app(TenantContext::class)->require()->name,
                'list' => $this->listName,
                'url' => $this->url(),
                'days' => PublicSignup::TTL_DAYS,
            ],
        );
    }

    /** The single-use confirmation link (GET shows a button; only the POST subscribes). */
    public function url(): string
    {
        return route('email_marketing.signup.confirm.show', ['token' => $this->token]);
    }
}
