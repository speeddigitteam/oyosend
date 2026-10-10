<?php

declare(strict_types=1);

namespace App\Core\Registration\Mail;

use App\Core\Registration\Models\WorkspaceRegistration;
use App\Core\Tenancy\Support\TenantHost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use SensitiveParameter;

/**
 * The link that confirms a sign-up. Platform mail: no workspace exists yet.
 * Encrypted on the queue, because the payload holds the one-time token.
 */
final class ConfirmRegistrationMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly WorkspaceRegistration $registration,
        #[SensitiveParameter]
        private readonly string $token,
    ) {
        $this->onQueue('notifications');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Confirm your email to create :company', ['company' => $this->registration->company]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.registration.confirm',
            with: [
                'name' => $this->registration->name,
                'company' => $this->registration->company,
                'address' => parse_url(app(TenantHost::class)->url($this->registration->slug), PHP_URL_HOST),
                'url' => route('central.register.confirm', ['token' => $this->token]),
                'expiresInHours' => (int) config('platform.registration.verification_ttl_hours', 24),
            ],
        );
    }
}
