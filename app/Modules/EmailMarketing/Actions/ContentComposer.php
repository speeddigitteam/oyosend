<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Actions;

use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\TenantContext;
use App\Modules\EmailMarketing\Models\Delivery;
use App\Modules\EmailMarketing\Support\EmailRenderer;
use App\Modules\EmailMarketing\Support\RenderedEmail;
use App\Modules\EmailMarketing\Support\TrackingTokens;
use Illuminate\Support\Facades\App;

/**
 * Builds the finished email of one recipient (or a sample one for previews) from stored blocks: variables from
 * the shared contact, the footer, click-tracking links and the web-version link.
 */
final class ContentComposer
{
    public function __construct(
        private readonly EmailRenderer $renderer,
        private readonly TrackingTokens $tokens,
        private readonly EmailMarketingSettings $settings,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    public function forContact(
        array $blocks,
        string $subject,
        ?string $preheader,
        Contact $contact,
        ?string $unsubscribeUrl,
        ?Delivery $delivery = null,
        bool $trackClicks = false,
    ): RenderedEmail {
        $values = [
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'full_name' => $contact->fullName(),
            'email' => $contact->email,
            'company' => $contact->company?->name,
            'workspace_name' => $this->context->require()->name,
            'current_year' => (string) now()->year,
            'unsubscribe_url' => $unsubscribeUrl,
        ];

        return $this->render($blocks, $subject, $preheader, $values, $delivery, $trackClicks, true);
    }

    /**
     * The web version of a sent email: same content, no personal unsubscribe token on the page.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    public function webVersion(array $blocks, string $subject, ?string $preheader, Contact $contact, Delivery $delivery, bool $trackClicks): RenderedEmail
    {
        $values = [
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'full_name' => $contact->fullName(),
            'email' => $contact->email,
            'company' => $contact->company?->name,
            'workspace_name' => $this->context->require()->name,
            'current_year' => (string) now()->year,
            'unsubscribe_url' => null,
        ];

        return $this->render($blocks, $subject, $preheader, $values, $delivery, $trackClicks, false);
    }

    /**
     * A preview with made-up recipient data. Nothing real is read.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    public function sample(array $blocks, string $subject, ?string $preheader): RenderedEmail
    {
        $values = [
            'first_name' => 'Alex',
            'last_name' => 'Rahman',
            'full_name' => 'Alex Rahman',
            'email' => 'alex@example.com',
            'company' => 'Example Ltd',
            'workspace_name' => $this->context->require()->name,
            'current_year' => (string) now()->year,
            'unsubscribe_url' => 'https://example.com/unsubscribe',
        ];

        return $this->render($blocks, $subject, $preheader, $values, null, false, false);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, string|null>  $values
     */
    private function render(array $blocks, string $subject, ?string $preheader, array $values, ?Delivery $delivery, bool $trackClicks, bool $withWebVersion): RenderedEmail
    {
        $track = $delivery !== null && $trackClicks
            ? fn (string $url): string => strlen($url) <= 1000 ? $this->tokens->clickUrl($delivery->id, $url) : $url
            : null;

        $previous = App::getLocale();
        App::setLocale($this->context->require()->locale);

        try {
            return $this->renderer->render(
                $blocks,
                $subject,
                $preheader,
                $values,
                $track,
                $this->settings->current()->footer_address,
                $delivery !== null && $withWebVersion ? $this->tokens->viewUrl($delivery->id) : null,
            );
        } finally {
            App::setLocale($previous);
        }
    }
}
