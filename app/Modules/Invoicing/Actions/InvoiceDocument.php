<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Core\Billing\Support\Money;
use App\Core\Tenancy\Storage\TenantStorage;
use App\Core\Tenancy\TenantContext;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Models\CreditNote;
use App\Modules\Invoicing\Models\Invoice;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The printable document. It is plain, self-contained HTML (inline CSS, the logo embedded as a
 * data URI) so the same markup serves the "Print / Save as PDF" page and the email attachment.
 * There is deliberately no PDF library: the browser produces the PDF.
 */
final class InvoiceDocument
{
    private const int MAX_LOGO_BYTES = 1_048_576;

    private const array LOGO_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantStorage $storage,
    ) {}

    public function html(Invoice|CreditNote $document): string
    {
        return view('invoicing.document', $this->data($document))->render();
    }

    /** @return array<string, mixed> */
    public function data(Invoice|CreditNote $document): array
    {
        return $document instanceof CreditNote ? $this->creditNote($document) : $this->invoice($document);
    }

    /** @return array<string, mixed> */
    private function invoice(Invoice $invoice): array
    {
        $invoice->loadMissing(['lines', 'contact', 'company']);
        $currency = $invoice->currency;
        $tenant = $this->context->require();
        $seller = $invoice->seller_snapshot ?? [
            'name' => $tenant->legal_name ?? $tenant->name,
            'address' => $tenant->address,
            'city' => $tenant->city,
            'postal_code' => $tenant->postal_code,
            'country' => $tenant->country,
            'tax_id' => $tenant->tax_id,
            'email' => $tenant->email,
            'phone' => $tenant->phone,
            'website' => $tenant->website,
        ];

        $totals = [
            ['label' => __('Subtotal'), 'value' => Money::format($invoice->subtotal_amount, $currency)],
        ];

        if ($invoice->discount_amount > 0) {
            $totals[] = ['label' => __('Discount'), 'value' => '-'.Money::format($invoice->discount_amount, $currency)];
        }

        $totals[] = ['label' => __('Tax'), 'value' => Money::format($invoice->tax_amount, $currency)];
        $totals[] = ['label' => __('Total'), 'value' => Money::format($invoice->total_amount, $currency), 'strong' => true];

        if ($invoice->paid_amount > 0) {
            $totals[] = ['label' => __('Paid'), 'value' => '-'.Money::format($invoice->paid_amount, $currency)];
        }

        if ($invoice->credited_amount > 0) {
            $totals[] = ['label' => __('Credited'), 'value' => '-'.Money::format($invoice->credited_amount, $currency)];
        }

        if ($invoice->status !== InvoiceStatus::Draft && $invoice->status !== InvoiceStatus::Void) {
            $totals[] = ['label' => __('Balance due'), 'value' => Money::format($invoice->balance_amount, $currency), 'strong' => true];
        }

        return [
            'kind' => 'invoice',
            'heading' => $invoice->status === InvoiceStatus::Draft ? __('Draft invoice') : __('Invoice'),
            'number' => $invoice->number,
            'status' => $this->statusLabel($invoice->status),
            'statusKey' => $invoice->status->value,
            'dates' => array_values(array_filter([
                $invoice->issued_on !== null ? ['label' => __('Invoice date'), 'value' => $this->date($invoice->issued_on)] : null,
                $invoice->due_on !== null ? ['label' => __('Due date'), 'value' => $this->date($invoice->due_on)] : null,
            ])),
            'reference' => $invoice->reference,
            'subject' => $invoice->title,
            'seller' => $this->party($seller, 'name'),
            'customer' => $this->customerBlock($invoice),
            'lines' => $this->lines($invoice->lines->all(), $currency),
            'totals' => $totals,
            'notes' => $invoice->notes,
            'terms' => $invoice->terms,
            'logo' => $this->logo(),
            'orgName' => $seller['name'] ?? $tenant->name,
            'locale' => app()->getLocale(),
            'relatesTo' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function creditNote(CreditNote $note): array
    {
        $note->loadMissing(['lines', 'invoice']);
        $invoice = $note->invoice;
        $currency = $note->currency;
        $tenant = $this->context->require();
        $seller = $invoice->seller_snapshot ?? ['name' => $tenant->legal_name ?? $tenant->name];

        $totals = [['label' => __('Subtotal'), 'value' => Money::format($note->subtotal_amount, $currency)]];

        if ($note->discount_amount > 0) {
            $totals[] = ['label' => __('Discount'), 'value' => '-'.Money::format($note->discount_amount, $currency)];
        }

        $totals[] = ['label' => __('Tax'), 'value' => Money::format($note->tax_amount, $currency)];
        $totals[] = ['label' => __('Credit total'), 'value' => Money::format($note->total_amount, $currency), 'strong' => true];

        return [
            'kind' => 'credit_note',
            'heading' => __('Credit note'),
            'number' => $note->number,
            'status' => null,
            'statusKey' => 'issued',
            'dates' => [['label' => __('Date'), 'value' => $this->date($note->issued_on)]],
            'reference' => $invoice->number,
            'subject' => $note->reason,
            'seller' => $this->party($seller, 'name'),
            'customer' => $this->customerBlock($invoice),
            'lines' => $this->lines($note->lines->all(), $currency),
            'totals' => $totals,
            'notes' => null,
            'terms' => null,
            'logo' => $this->logo(),
            'orgName' => $seller['name'] ?? $tenant->name,
            'locale' => app()->getLocale(),
            'relatesTo' => $invoice->number,
        ];
    }

    /**
     * @param  list<object>  $lines
     * @return list<array<string, string|null>>
     */
    private function lines(array $lines, string $currency): array
    {
        return array_map(fn (object $line): array => [
            'name' => $line->name,
            'description' => $line->description,
            'quantity' => InvoiceMath::quantityLabel($line->quantity_milli).' '.$line->unit,
            'unitPrice' => Money::format($line->unit_price_amount, $currency),
            'discount' => $line->discount_amount > 0 ? '-'.Money::format($line->discount_amount, $currency) : null,
            'tax' => $line->tax_bps > 0
                ? rtrim(rtrim(number_format($line->tax_bps / 100, 2, '.', ''), '0'), '.').'%'
                : null,
            'total' => Money::format($line->line_total_amount, $currency),
        ], $lines);
    }

    /**
     * @param  array<string, mixed>  $party
     * @return array{name: string, lines: list<string>}
     */
    private function party(array $party, string $nameKey): array
    {
        $lines = [];

        foreach (['address', 'city', 'postal_code', 'country'] as $key) {
            if (isset($party[$key]) && trim((string) $party[$key]) !== '') {
                $lines[] = (string) $party[$key];
            }
        }

        if (isset($party['tax_id']) && trim((string) $party['tax_id']) !== '') {
            $lines[] = __('Tax ID').': '.$party['tax_id'];
        }

        foreach (['email', 'phone', 'website'] as $key) {
            if (isset($party[$key]) && trim((string) $party[$key]) !== '') {
                $lines[] = (string) $party[$key];
            }
        }

        return ['name' => (string) ($party[$nameKey] ?? ''), 'lines' => $lines];
    }

    /** @return array{name: string, lines: list<string>} */
    private function customerBlock(Invoice $invoice): array
    {
        $lines = [];

        if ($invoice->customer_contact_name !== null) {
            $lines[] = __('Attn').': '.$invoice->customer_contact_name;
        }

        if ($invoice->customer_address !== null) {
            foreach (preg_split('/\R/u', $invoice->customer_address) ?: [] as $part) {
                $lines[] = $part;
            }
        }

        if ($invoice->customer_tax_id !== null) {
            $lines[] = __('Tax ID').': '.$invoice->customer_tax_id;
        }

        foreach ([$invoice->customer_email, $invoice->customer_phone] as $contact) {
            if ($contact !== null) {
                $lines[] = $contact;
            }
        }

        if ($invoice->customer_name === null && $invoice->status === InvoiceStatus::Draft) {
            $name = $invoice->company->name ?? $invoice->contact?->fullName() ?? '';

            return ['name' => $name, 'lines' => $lines];
        }

        return ['name' => (string) $invoice->customer_name, 'lines' => $lines];
    }

    private function statusLabel(InvoiceStatus $status): string
    {
        return match ($status) {
            InvoiceStatus::Draft => __('Draft'),
            InvoiceStatus::Issued => __('Issued'),
            InvoiceStatus::PartiallyPaid => __('Partially paid'),
            InvoiceStatus::Paid => __('Paid'),
            InvoiceStatus::Overdue => __('Overdue'),
            InvoiceStatus::Void => __('Void'),
        };
    }

    private function date(Carbon $date): string
    {
        return $date->copy()->locale(app()->getLocale())->isoFormat('LL');
    }

    /** The workspace logo as a data URI, or null. A missing or unreadable file never blocks the document. */
    private function logo(): ?string
    {
        $path = $this->context->require()->logo_path;

        if ($path === null) {
            return null;
        }

        try {
            $bytes = $this->storage->get($path);

            if ($bytes === null || $bytes === '' || strlen($bytes) > self::MAX_LOGO_BYTES) {
                return null;
            }

            $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

            if (! is_string($type) || ! in_array($type, self::LOGO_TYPES, true)) {
                return null;
            }

            return 'data:'.$type.';base64,'.base64_encode($bytes);
        } catch (Throwable) {
            return null;
        }
    }
}
