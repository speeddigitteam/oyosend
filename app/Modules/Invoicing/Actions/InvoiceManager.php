<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\UsageLimits;
use App\Core\Billing\UsageMeter;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Events\InvoiceIssued;
use App\Modules\Invoicing\Events\InvoiceOverdue;
use App\Modules\Invoicing\Models\BillableTime;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invoice lifecycle: draft -> issued -> partially_paid / paid / overdue, or void.
 *
 * Every transition locks the invoice row, checks the status it expects, writes its audit
 * entry in the same transaction and announces itself with a domain event after commit.
 */
final class InvoiceManager
{
    public const string LIMIT = 'invoicing.invoices_per_month';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InvoiceLines $lineBuilder,
        private readonly InvoicingSettings $settings,
        private readonly NumberAllocator $numbers,
        private readonly TenantContext $context,
        private readonly UsageLimits $limits,
        private readonly UsageMeter $meter,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  contact_id, company_id, currency, title, reference, issued_on, due_on, terms, notes, source_*
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $attributes, array $lines, ?TenantMembership $owner = null): Invoice
    {
        $currency = $this->currency($attributes['currency'] ?? null);
        $this->customer($attributes['contact_id'] ?? null, $attributes['company_id'] ?? null);
        $prepared = $this->lineBuilder->prepare($lines);
        $this->assertDates($attributes['issued_on'] ?? null, $attributes['due_on'] ?? null);
        $defaults = $this->settings->current();

        return DB::transaction(function () use ($attributes, $currency, $prepared, $owner, $defaults): Invoice {
            $invoice = new Invoice([
                'contact_id' => $attributes['contact_id'] ?? null,
                'company_id' => $attributes['company_id'] ?? null,
                'recurring_template_id' => $attributes['recurring_template_id'] ?? null,
                'status' => InvoiceStatus::Draft,
                'currency' => $currency,
                'title' => $attributes['title'] ?? null,
                'reference' => $attributes['reference'] ?? null,
                'issued_on' => $attributes['issued_on'] ?? null,
                'due_on' => $attributes['due_on'] ?? null,
                'terms' => $attributes['terms'] ?? $defaults->default_terms,
                'notes' => $attributes['notes'] ?? $defaults->default_notes,
                'source_type' => $attributes['source_type'] ?? null,
                'source_id' => $attributes['source_id'] ?? null,
                'source_key' => $attributes['source_key'] ?? null,
                ...$prepared['totals'],
                'paid_amount' => 0,
                'credited_amount' => 0,
                'balance_amount' => 0,
            ]);

            if ($owner !== null) {
                $invoice->assignOwner($owner);
            }

            $invoice->save();
            $this->saveLines($invoice, $prepared['lines']);

            return $invoice->load('lines');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(Invoice $invoice, array $attributes, array $lines): Invoice
    {
        $currency = $this->currency($attributes['currency'] ?? null);
        $this->customer($attributes['contact_id'] ?? null, $attributes['company_id'] ?? null);
        $prepared = $this->lineBuilder->prepare($lines);
        $this->assertDates($attributes['issued_on'] ?? null, $attributes['due_on'] ?? null);

        return DB::transaction(function () use ($invoice, $attributes, $currency, $prepared): Invoice {
            $locked = $this->lock($invoice);
            $this->assertStatus($locked, InvoiceStatus::Draft, 'Only a draft invoice can be edited.');

            foreach ($locked->lines()->get() as $line) {
                $line->delete();
            }

            $locked->fill([
                'contact_id' => $attributes['contact_id'] ?? null,
                'company_id' => $attributes['company_id'] ?? null,
                'currency' => $currency,
                'title' => $attributes['title'] ?? null,
                'reference' => $attributes['reference'] ?? null,
                'issued_on' => $attributes['issued_on'] ?? null,
                'due_on' => $attributes['due_on'] ?? null,
                'terms' => $attributes['terms'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                ...$prepared['totals'],
            ]);
            $locked->save();
            $this->saveLines($locked, $prepared['lines']);

            return $locked->load('lines');
        });
    }

    /** Remove a draft. A draft has no number, so nothing is lost from the sequence. */
    public function delete(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $locked = $this->lock($invoice);
            $this->assertStatus($locked, InvoiceStatus::Draft, 'Only a draft invoice can be deleted. Void an issued invoice instead.');
            $this->releaseBillableTime($locked);
            $locked->delete();
        });
    }

    public function issue(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $locked = $this->lock($invoice);
            $this->assertStatus($locked, InvoiceStatus::Draft, 'Only a draft invoice can be issued.');

            $lines = $locked->lines()->get();

            if ($lines->isEmpty() || $locked->total_amount < 1) {
                throw ValidationException::withMessages(['invoice' => __('An invoice needs at least one line and a positive total.')]);
            }

            $customer = $this->customer($locked->contact_id, $locked->company_id);
            $settings = $this->settings->current();
            $issuedOn = $locked->issued_on !== null
                ? $locked->issued_on->toImmutable()->startOfDay()
                : $this->settings->today();
            $dueOn = $locked->due_on !== null
                ? $locked->due_on->toImmutable()->startOfDay()
                : $issuedOn->addDays($settings->default_due_days);

            if ($dueOn->lt($issuedOn)) {
                throw ValidationException::withMessages(['due_on' => __('The due date cannot be before the invoice date.')]);
            }

            $this->limits->ensure(self::LIMIT, 1, 'invoice');

            $locked->fill([
                ...$customer,
                'number' => $this->numbers->next(NumberAllocator::INVOICE, $issuedOn, $settings),
                'status' => InvoiceStatus::Issued,
                'issued_on' => $issuedOn->toDateString(),
                'due_on' => $dueOn->toDateString(),
                'issued_at' => now(),
                'balance_amount' => $locked->total_amount,
                'paid_amount' => 0,
                'credited_amount' => 0,
                'seller_snapshot' => $this->seller(),
            ]);
            $locked->save();

            $this->meter->record(self::LIMIT, 1, 'invoice:'.$locked->id, ['number' => $locked->number]);
            $this->audit->record('invoicing.invoice.issued', $locked, [
                'number' => $locked->number,
                'total_amount' => $locked->total_amount,
                'currency' => $locked->currency,
            ]);
            event(InvoiceIssued::from($locked));

            return $locked->load('lines');
        });
    }

    public function void(Invoice $invoice, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason): Invoice {
            $locked = $this->lock($invoice);

            if ($locked->status === InvoiceStatus::Void) {
                return $locked;
            }

            if (! $locked->status->isOpen()) {
                throw ValidationException::withMessages(['invoice' => __('Only an issued, unpaid invoice can be voided.')]);
            }

            $hasPayment = $locked->payments()->whereNull('reversed_at')->exists();

            if ($hasPayment || $locked->creditNotes()->exists()) {
                throw ValidationException::withMessages([
                    'invoice' => __('An invoice with payments or credit notes cannot be voided. Reverse the payments first, or issue a credit note.'),
                ]);
            }

            $locked->status = InvoiceStatus::Void;
            $locked->voided_at = now();
            $locked->void_reason = mb_substr(trim($reason), 0, 500);
            $locked->balance_amount = 0;
            $locked->save();
            $this->releaseBillableTime($locked);
            $this->audit->record('invoicing.invoice.voided', $locked, ['number' => $locked->number, 'reason' => $locked->void_reason]);

            return $locked;
        });
    }

    /** Flip one past-due open invoice to overdue. Safe to repeat. Returns whether it changed. */
    public function markOverdue(Invoice $invoice): bool
    {
        return DB::transaction(function () use ($invoice): bool {
            $locked = $this->lock($invoice);
            $today = $this->settings->today()->toDateString();

            if (! in_array($locked->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], true)
                || $locked->balance_amount < 1
                || $locked->due_on === null
                || $locked->due_on->toDateString() >= $today) {
                return false;
            }

            $locked->status = InvoiceStatus::Overdue;
            $locked->overdue_at = now();
            $locked->save();
            $this->audit->record('invoicing.invoice.overdue', $locked, ['number' => $locked->number]);
            event(InvoiceOverdue::from($locked, max(1, InvoiceLedger::daysBetween($locked->due_on->toDateString(), $today))));

            return true;
        });
    }

    /** Mark every past-due open invoice of the active workspace overdue. */
    public function markOverdueInvoices(): int
    {
        $count = 0;
        $today = $this->settings->today()->toDateString();

        Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid])
            ->where('due_on', '<', $today)
            ->where('balance_amount', '>', 0)
            ->chunkById(100, function ($invoices) use (&$count): void {
                foreach ($invoices as $invoice) {
                    if ($this->markOverdue($invoice)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function lock(Invoice $invoice): Invoice
    {
        /** @var Invoice */
        return Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
    }

    private function assertStatus(Invoice $invoice, InvoiceStatus $expected, string $message): void
    {
        if ($invoice->status !== $expected) {
            throw ValidationException::withMessages(['invoice' => __($message)]);
        }
    }

    private function currency(mixed $currency): string
    {
        $currency = strtoupper(trim((string) $currency));

        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw ValidationException::withMessages(['currency' => __('Choose a three-letter currency code.')]);
        }

        return $currency;
    }

    private function assertDates(mixed $issuedOn, mixed $dueOn): void
    {
        if ($issuedOn !== null && $dueOn !== null && (string) $dueOn < (string) $issuedOn) {
            throw ValidationException::withMessages(['due_on' => __('The due date cannot be before the invoice date.')]);
        }
    }

    /**
     * The customer as it reads today. Copied onto the invoice at issue so a later edit
     * of the shared contact or company never rewrites an issued document.
     *
     * @return array<string, string|null>
     */
    public function customer(?string $contactId, ?string $companyId): array
    {
        $contact = $contactId === null ? null : Contact::query()->find($contactId);
        $company = $companyId === null ? null : Company::query()->find($companyId);

        if (($contactId !== null && $contact === null) || ($companyId !== null && $company === null)
            || ($contact === null && $company === null)) {
            throw ValidationException::withMessages(['customer' => __('Choose an existing contact or company.')]);
        }

        $place = $company ?? $contact;
        $address = array_filter([
            $place->address,
            $place->city,
            $place->country,
        ], static fn (?string $part): bool => $part !== null && trim($part) !== '');

        return [
            'customer_name' => $company !== null ? $company->name : $contact->fullName(),
            'customer_contact_name' => $company !== null && $contact !== null ? $contact->fullName() : null,
            'customer_email' => $contact->email ?? $company?->email,
            'customer_phone' => $contact->phone ?? $company?->phone,
            'customer_address' => $address === [] ? null : implode("\n", $address),
            'customer_tax_id' => $company?->tax_id,
        ];
    }

    /** @return array<string, string|null> */
    private function seller(): array
    {
        $tenant = $this->context->require();

        return [
            'name' => $tenant->legal_name ?? $tenant->name,
            'trade_name' => $tenant->name,
            'address' => $tenant->address,
            'city' => $tenant->city,
            'postal_code' => $tenant->postal_code,
            'country' => $tenant->country,
            'tax_id' => $tenant->tax_id,
            'email' => $tenant->email,
            'phone' => $tenant->phone,
            'website' => $tenant->website,
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    private function saveLines(Invoice $invoice, array $lines): void
    {
        foreach ($lines as $line) {
            InvoiceLine::query()->create([...$line, 'invoice_id' => $invoice->id]);
        }
    }

    private function releaseBillableTime(Invoice $invoice): void
    {
        BillableTime::query()->where('invoice_id', $invoice->id)->update(['invoice_id' => null, 'invoiced_at' => null]);
    }
}
