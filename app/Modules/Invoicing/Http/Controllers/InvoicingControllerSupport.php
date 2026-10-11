<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use App\Modules\Invoicing\Actions\InvoicingSettings;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceLine;
use App\Modules\Invoicing\Models\TaxRate;
use Illuminate\Http\Request;

final class InvoicingControllerSupport
{
    public function __construct(
        private readonly AccessChecker $access,
        private readonly RecordAccess $records,
        private readonly InvoicingSettings $settings,
        private readonly TenantContext $context,
    ) {}

    public function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    public function membership(User $actor): TenantMembership
    {
        return $this->access->membership($actor) ?? abort(403);
    }

    /**
     * The submitted customer ids, narrowed to the contacts and companies the actor may see.
     *
     * @param  array<string, mixed>  $input
     * @return array{contact_id: string|null, company_id: string|null}
     */
    public function customer(array $input, User $actor): array
    {
        $contactId = $input['contact_id'] ?? null;
        $companyId = $input['company_id'] ?? null;

        return [
            'contact_id' => $contactId === null ? null
                : $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)->findOrFail($contactId)->id,
            'company_id' => $companyId === null ? null
                : $this->records->constrain(Company::query(), $actor, ContactController::VIEW)->findOrFail($companyId)->id,
        ];
    }

    /**
     * Everything an invoice or template form needs. Customers are narrowed to what the actor
     * may see, as the shared contact pickers are everywhere else.
     *
     * @return array<string, mixed>
     */
    public function formOptions(User $actor): array
    {
        $settings = $this->settings->current();

        return [
            'contacts' => $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)
                ->orderBy('first_name')->orderBy('last_name')->limit(500)->get(['id', 'first_name', 'last_name', 'email'])
                ->map(fn (Contact $contact): array => ['id' => $contact->id, 'name' => $contact->fullName(), 'email' => $contact->email])->all(),
            'companies' => $this->records->constrain(Company::query(), $actor, ContactController::VIEW)
                ->orderBy('name')->limit(500)->get(['id', 'name', 'email'])
                ->map(fn (Company $company): array => ['id' => $company->id, 'name' => $company->name, 'email' => $company->email])->all(),
            'taxRates' => $this->taxRates(activeOnly: true),
            'currencies' => array_values((array) config('platform.currencies', [])),
            'defaults' => [
                'currency' => $this->context->require()->currency,
                'dueDays' => $settings->default_due_days,
                'terms' => $settings->default_terms,
                'notes' => $settings->default_notes,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function emptyFormOptions(): array
    {
        return ['contacts' => [], 'companies' => [], 'taxRates' => [], 'currencies' => [], 'defaults' => [
            'currency' => null, 'dueDays' => null, 'terms' => null, 'notes' => null,
        ]];
    }

    /** @return list<array{id: string, name: string, rateBps: int, isDefault: bool, isActive: bool}> */
    public function taxRates(bool $activeOnly = false): array
    {
        return TaxRate::query()
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn (TaxRate $rate): array => [
                'id' => $rate->id,
                'name' => $rate->name,
                'rateBps' => $rate->rate_bps,
                'isDefault' => $rate->is_default,
                'isActive' => $rate->is_active,
            ])->all();
    }

    /** @return array<string, mixed> */
    public function summary(Invoice $invoice): array
    {
        $customerName = $invoice->customer_name
            ?? $invoice->company->name
            ?? $invoice->contact?->fullName();

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status->value,
            'title' => $invoice->title,
            'reference' => $invoice->reference,
            'currency' => $invoice->currency,
            'issuedOn' => $invoice->issued_on?->toDateString(),
            'dueOn' => $invoice->due_on?->toDateString(),
            'totalAmount' => $invoice->total_amount,
            'paidAmount' => $invoice->paid_amount,
            'creditedAmount' => $invoice->credited_amount,
            'balanceAmount' => $invoice->balance_amount,
            'customer' => ['name' => $customerName, 'contactId' => $invoice->contact_id, 'companyId' => $invoice->company_id],
            'owner' => $invoice->owner === null ? null : ['id' => $invoice->owner->id, 'name' => $invoice->owner->user->name],
            'sentAt' => $invoice->sent_at?->toIso8601String(),
            'source' => $invoice->source_type === null ? null : ['type' => $invoice->source_type, 'id' => $invoice->source_id],
            'createdAt' => $invoice->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function line(InvoiceLine $line): array
    {
        return [
            'id' => $line->id,
            'position' => $line->position,
            'name' => $line->name,
            'description' => $line->description,
            'unit' => $line->unit,
            'quantityMilli' => $line->quantity_milli,
            'unitPriceAmount' => $line->unit_price_amount,
            'grossAmount' => $line->gross_amount,
            'discountBps' => $line->discount_bps,
            'discountAmount' => $line->discount_amount,
            'netAmount' => $line->net_amount,
            'taxRateId' => $line->tax_rate_id,
            'taxName' => $line->tax_name,
            'taxBps' => $line->tax_bps,
            'taxAmount' => $line->tax_amount,
            'lineTotalAmount' => $line->line_total_amount,
        ];
    }
}
