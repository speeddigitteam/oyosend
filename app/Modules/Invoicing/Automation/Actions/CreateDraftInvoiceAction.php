<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Modules\Invoicing\Actions\InvoiceSources;
use App\Modules\Invoicing\Automation\ActionReceiptStore;

/**
 * Creates a draft invoice only. It never issues, sends or records a payment: those stay
 * human decisions (or the explicit auto-issue of a recurring template).
 */
final class CreateDraftInvoiceAction implements ActionDefinition
{
    public function __construct(
        private readonly InvoiceSources $sources,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'invoicing.invoice.create_draft';
    }

    public static function label(): string
    {
        return 'automation.actions.invoicing.invoice.create_draft';
    }

    public static function module(): string
    {
        return 'invoicing';
    }

    public static function requiredPermission(): string
    {
        return 'invoicing.invoices.edit';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['currency', 'lines'],
            'properties' => [
                'contact_id' => ['type' => ['string', 'null']],
                'company_id' => ['type' => ['string', 'null']],
                'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3],
                'title' => ['type' => ['string', 'null'], 'maxLength' => 200],
                'reference' => ['type' => ['string', 'null'], 'maxLength' => 120],
                'due_days' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 365],
                'lines' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 200,
                    'items' => [
                        'type' => 'object',
                        'required' => ['name', 'quantity_milli', 'unit_price_amount'],
                        'properties' => [
                            'name' => ['type' => 'string', 'maxLength' => 180],
                            'description' => ['type' => ['string', 'null']],
                            'unit' => ['type' => ['string', 'null'], 'maxLength' => 40],
                            'quantity_milli' => ['type' => 'integer', 'minimum' => 1],
                            'unit_price_amount' => ['type' => 'integer', 'minimum' => 0],
                            'discount_amount' => ['type' => ['integer', 'null'], 'minimum' => 0],
                            'discount_bps' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 10000],
                            'tax_rate_id' => ['type' => ['string', 'null']],
                            'tax_bps' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 10000],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['invoice_id', 'status'],
            'properties' => ['invoice_id' => ['type' => 'string'], 'status' => ['type' => 'string'], 'total_amount' => ['type' => 'integer']],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input, $idempotencyKey): array {
            $invoice = $this->sources->fromAutomation($input, $idempotencyKey);

            return [
                'invoice_id' => $invoice->id,
                'status' => $invoice->status->value,
                'total_amount' => $invoice->total_amount,
            ];
        });
    }
}
