<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Listeners;

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Events\DomainEvent;
use App\Core\Tenancy\TenantContext;
use App\Modules\Invoicing\Actions\InvoiceSources;
use App\Modules\Invoicing\Actions\InvoicingSettings;
use Illuminate\Validation\ValidationException;

/**
 * The Phase 6 end of the `crm.quote.accepted` hand-off.
 *
 * It reads only the event's documented payload (ids, currency, integer totals and line
 * snapshots) and never touches a CRM model. It creates a draft, never an issued invoice:
 * a person reviews and issues it. Duplicate deliveries return the existing draft.
 */
final class CreateInvoiceFromAcceptedQuote
{
    public const string EVENT = 'crm.quote.accepted';

    public function __construct(
        private readonly EntitlementChecker $entitlements,
        private readonly InvoiceSources $sources,
        private readonly InvoicingSettings $settings,
        private readonly TenantContext $context,
    ) {}

    public function handle(DomainEvent $event): void
    {
        if ($event::name() !== self::EVENT || ! $this->context->check() || $this->context->id() !== $event->tenantId()) {
            return;
        }

        if (! $this->entitlements->allows($this->context->require(), 'invoicing')
            || ! $this->settings->current()->invoice_accepted_quotes) {
            return;
        }

        try {
            $this->sources->fromAcceptedQuote($event->payload());
        } catch (ValidationException) {
            // A quotation that cannot be invoiced (no customer left, bad data) must never
            // make accepting it fail: the sales side has already committed.
        }
    }
}
