<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Http\Requests;

use App\Modules\Invoicing\Actions\InvoiceMath;

/** Validation fragments shared by invoices, credit notes and recurring templates. */
final class InvoicingRules
{
    /** @return array<string, list<string>> */
    public static function lines(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:'.InvoiceMath::MAX_LINES],
            'lines.*.name' => ['required', 'string', 'max:180'],
            'lines.*.description' => ['nullable', 'string', 'max:5000'],
            'lines.*.unit' => ['nullable', 'string', 'max:40'],
            'lines.*.quantity_milli' => ['required', 'integer', 'between:1,'.InvoiceMath::MAX_QUANTITY_MILLI],
            'lines.*.unit_price_amount' => ['required', 'integer', 'between:0,'.InvoiceMath::MAX_UNIT_PRICE],
            'lines.*.discount_amount' => ['nullable', 'integer', 'min:0', 'max:'.InvoiceMath::MAX_AMOUNT],
            'lines.*.discount_bps' => ['nullable', 'integer', 'between:0,10000'],
            'lines.*.tax_rate_id' => ['nullable', 'string', 'size:26'],
            'lines.*.tax_bps' => ['nullable', 'integer', 'between:0,10000'],
            'lines.*.tax_name' => ['nullable', 'string', 'max:80'],
        ];
    }

    /** @return array<string, list<string>> */
    public static function customer(): array
    {
        return [
            'contact_id' => ['nullable', 'string', 'size:26', 'required_without:company_id'],
            'company_id' => ['nullable', 'string', 'size:26', 'required_without:contact_id'],
        ];
    }
}
