<?php

declare(strict_types=1);

namespace App\Platform\Mail;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Core\Tenancy\Support\TenantHost;
use App\Core\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Password;

/**
 * Tells an owner that their workspace can be used: it was created for them
 * from the Super Admin panel, or their sign-up was approved.
 *
 * Someone who has no password yet gets a link to choose one. The link is made
 * when the mail is built - in the workspace's own context, so it opens on the
 * workspace's host - and is never stored in the queue payload.
 */
final class WorkspaceReadyMail extends Mailable implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  bool  $needsPassword  The account was created for them and has no password they know.
     */
    public function __construct(
        public readonly User $owner,
        public readonly bool $needsPassword,
    ) {
        $this->onQueue('notifications');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __(':workspace is ready', ['workspace' => app(TenantContext::class)->require()->name]),
        );
    }

    public function content(): Content
    {
        $tenant = app(TenantContext::class)->require();
        $hosts = app(TenantHost::class);

        return new Content(
            markdown: 'mail.workspace.ready',
            with: [
                'workspace' => $tenant->name,
                'address' => parse_url($hosts->url($tenant), PHP_URL_HOST),
                'loginUrl' => $hosts->url($tenant, 'login'),
                'setPasswordUrl' => $this->needsPassword ? route('auth.password.reset', [
                    'token' => Password::broker('users')->createToken($this->owner),
                    'email' => $this->owner->email,
                ]) : null,
            ],
        );
    }
}
