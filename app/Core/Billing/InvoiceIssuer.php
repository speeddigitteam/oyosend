<?php

declare(strict_types=1);

namespace App\Core\Billing;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Enums\InvoiceKind;
use App\Core\Billing\Enums\InvoiceStatus;
use App\Core\Billing\Mail\InvoiceIssuedMail;
use App\Core\Billing\Models\Invoice;
use App\Core\Billing\Models\Plan;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\Support\BillingContact;
use App\Core\Billing\Support\InvoiceNumbers;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Issues and withdraws the platform's invoices to the active workspace.
 *
 * A workspace has at most one open invoice at a time: whatever changes what
 * is owed voids the open one first and issues another.
 */
final class InvoiceIssuer
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly InvoiceNumbers $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Call inside a database transaction.
     *
     * @param  int  $amount  Price of the period, in minor units.
     * @param  CarbonInterface  $periodStart  Start of the period this invoice buys.
     * @param  int  $credit  Value of what was already paid for and not used, taken off the total.
     */
    public function issue(
        Subscription $subscription,
        InvoiceKind $kind,
        Plan $plan,
        BillingInterval $interval,
        int $amount,
        CarbonInterface $periodStart,
        CarbonInterface $dueAt,
        int $credit = 0,
    ): Invoice {
        $tenant = $this->context->require();
        $credit = max(0, min($credit, $amount));

        $lines = [['type' => 'plan', 'plan' => $plan->name, 'interval' => $interval->value, 'amount' => $amount]];

        if ($credit > 0) {
            $lines[] = ['type' => 'credit', 'amount' => -$credit];
        }

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->create([
            'subscription_id' => $subscription->getKey(),
            'number' => $this->numbers->next(),
            'kind' => $kind,
            'status' => InvoiceStatus::Open,
            'plan_id' => $plan->getKey(),
            'interval' => $interval,
            'period_start' => $periodStart,
            'period_end' => $interval->addTo($periodStart),
            'currency' => $plan->currency,
            'subtotal_amount' => $amount,
            'credit_amount' => $credit,
            'total_amount' => $amount - $credit,
            'lines' => $lines,
            'billed_to' => [
                'name' => $tenant->legal_name ?? $tenant->name,
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'address' => $tenant->address,
                'city' => $tenant->city,
                'postal_code' => $tenant->postal_code,
                'country' => $tenant->country,
                'tax_id' => $tenant->tax_id,
            ],
            'issued_at' => Carbon::now(),
            'due_at' => $dueAt,
        ]);

        $this->audit->record('billing.invoice_issued', $invoice, [
            'plan' => $plan->key,
            'interval' => $interval->value,
            'total_amount' => $invoice->total_amount,
            'currency' => $invoice->currency,
        ]);

        // Nothing to ask for when credit covers the whole price.
        if ($invoice->total_amount > 0 && ($contact = BillingContact::ofActiveWorkspace()) !== null) {
            Mail::to($contact->email)->locale($contact->locale)->queue((new InvoiceIssuedMail($invoice))->afterCommit());
        }

        return $invoice;
    }

    public function void(Invoice $invoice): void
    {
        if (! $invoice->isOpen()) {
            return;
        }

        $invoice->update(['status' => InvoiceStatus::Void, 'voided_at' => Carbon::now()]);

        $this->audit->record('billing.invoice_voided', $invoice);
    }

    /**
     * Withdraw whatever the workspace was being asked to pay.
     */
    public function voidOpen(): void
    {
        Invoice::query()->open()->lockForUpdate()->get()->each($this->void(...));
    }

    public function hasOpen(): bool
    {
        return Invoice::query()->open()->exists();
    }

    public function open(): ?Invoice
    {
        return Invoice::query()->open()->latest('issued_at')->first();
    }
}
