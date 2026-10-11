<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Core\Identity\Models\TenantMembership;
use App\Modules\Invoicing\Models\BillableTime;
use App\Modules\Invoicing\Models\Invoice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Draft invoices created from something else: an accepted CRM quotation, approved
 * billable project time, an automation step. Each source works from plain ids and
 * scalars handed over by a documented event or contract, never from another module's
 * models, and each is idempotent.
 */
final class InvoiceSources
{
    public const string TYPE_QUOTE = 'crm_quote';

    public const string TYPE_PROJECT = 'project';

    public const string TYPE_AUTOMATION = 'automation';

    public const string TYPE_RECURRING = 'recurring';

    public function __construct(
        private readonly InvoiceManager $invoices,
        private readonly InvoicingSettings $settings,
    ) {}

    /**
     * A draft from the `crm.quote.accepted` payload. A repeated delivery of the same
     * event returns the draft that already exists (null is never returned for a new one).
     *
     * @param  array<string, mixed>  $payload
     */
    public function fromAcceptedQuote(array $payload, ?TenantMembership $owner = null): Invoice
    {
        $quoteId = (string) ($payload['quote_id'] ?? '');

        if ($quoteId === '' || ! is_array($payload['lines'] ?? null) || $payload['lines'] === []) {
            throw ValidationException::withMessages(['quote' => __('The quotation has no lines to invoice.')]);
        }

        $key = self::TYPE_QUOTE.':'.$quoteId;
        $existing = Invoice::query()->where('source_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        $lines = array_map(static fn (array $line): array => [
            'name' => (string) ($line['name'] ?? ''),
            'description' => $line['description'] ?? null,
            'unit' => $line['unit'] ?? null,
            'quantity_milli' => (int) ($line['quantity_milli'] ?? 0),
            'unit_price_amount' => (int) ($line['unit_price_amount'] ?? 0),
            'discount_amount' => (int) ($line['discount_amount'] ?? 0),
            'tax_bps' => (int) ($line['tax_bps'] ?? 0),
        ], array_values(array_filter($payload['lines'], 'is_array')));

        try {
            return $this->invoices->create([
                'contact_id' => $payload['contact_id'] ?? null,
                'company_id' => $payload['company_id'] ?? null,
                'currency' => $payload['currency'] ?? null,
                'title' => __('Invoice for quotation :number', ['number' => (string) ($payload['quote_number'] ?? '')]),
                'reference' => isset($payload['quote_number']) ? (string) $payload['quote_number'] : null,
                'source_type' => self::TYPE_QUOTE,
                'source_id' => $quoteId,
                'source_key' => $key,
            ], $lines, $owner);
        } catch (UniqueConstraintViolationException) {
            return Invoice::query()->where('source_key', $key)->firstOrFail();
        }
    }

    /**
     * Bill the approved, not yet invoiced, billable time of one project. The project is an
     * opaque id from the Projects event contract; its name, if the caller has one, is a label.
     */
    public function fromBillableTime(
        string $projectId,
        ?string $contactId,
        ?string $companyId,
        string $currency,
        ?string $label = null,
        ?TenantMembership $owner = null,
    ): Invoice {
        $currency = strtoupper($currency);

        return DB::transaction(function () use ($projectId, $contactId, $companyId, $currency, $label, $owner): Invoice {
            $entries = BillableTime::query()
                ->where('project_id', $projectId)
                ->where('currency', $currency)
                ->whereNull('invoice_id')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            if ($entries->isEmpty()) {
                throw ValidationException::withMessages(['project_id' => __('There is no approved billable time to invoice for this project.')]);
            }

            $name = $label !== null && trim($label) !== '' ? trim($label) : __('Project time');
            $lines = [];

            foreach ($entries->groupBy('rate_amount') as $rate => $group) {
                $minutes = InvoiceMath::sum($group->pluck('minutes')->all());
                $hours = intdiv($minutes, 60);
                $rest = $minutes % 60;
                $lines[] = [
                    'name' => $name,
                    'description' => __(':hours h :minutes min across :count time entries', [
                        'hours' => $hours,
                        'minutes' => $rest,
                        'count' => $group->count(),
                    ]),
                    'unit' => 'hour',
                    'quantity_milli' => InvoiceMath::hoursMilli($minutes),
                    'unit_price_amount' => (int) $rate,
                    'tax_bps' => 0,
                ];
            }

            $invoice = $this->invoices->create([
                'contact_id' => $contactId,
                'company_id' => $companyId,
                'currency' => $currency,
                'title' => $name,
                'source_type' => self::TYPE_PROJECT,
                'source_id' => $projectId,
            ], $lines, $owner);

            BillableTime::query()
                ->whereIn('id', $entries->pluck('id')->all())
                ->update(['invoice_id' => $invoice->id, 'invoiced_at' => now()]);

            return $invoice;
        });
    }

    /**
     * The automation action: one draft per idempotency key.
     *
     * @param  array<string, mixed>  $input
     */
    public function fromAutomation(array $input, string $idempotencyKey): Invoice
    {
        $key = self::TYPE_AUTOMATION.':'.$idempotencyKey;
        $existing = Invoice::query()->where('source_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        $dueOn = isset($input['due_days'])
            ? $this->settings->today()->addDays((int) $input['due_days'])->toDateString()
            : null;

        /** @var list<array<string, mixed>> $lines */
        $lines = is_array($input['lines'] ?? null) ? array_values($input['lines']) : [];

        return $this->invoices->create([
            'contact_id' => $input['contact_id'] ?? null,
            'company_id' => $input['company_id'] ?? null,
            'currency' => $input['currency'] ?? null,
            'title' => $input['title'] ?? null,
            'reference' => $input['reference'] ?? null,
            'due_on' => $dueOn,
            'source_type' => self::TYPE_AUTOMATION,
            'source_id' => null,
            'source_key' => $key,
        ], $lines);
    }
}
