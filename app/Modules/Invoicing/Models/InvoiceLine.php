<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property string|null $tax_rate_id
 * @property int $position
 * @property string $name
 * @property string|null $description
 * @property string $unit
 * @property int $quantity_milli
 * @property int $unit_price_amount
 * @property int $gross_amount
 * @property int|null $discount_bps
 * @property int $discount_amount
 * @property int $net_amount
 * @property string|null $tax_name
 * @property int $tax_bps
 * @property int $tax_amount
 * @property int $line_total_amount
 */
class InvoiceLine extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'invoicing_invoice_lines';

    /** @var list<string> */
    protected $fillable = [
        'invoice_id', 'tax_rate_id', 'position', 'name', 'description', 'unit', 'quantity_milli',
        'unit_price_amount', 'gross_amount', 'discount_bps', 'discount_amount', 'net_amount', 'tax_name',
        'tax_bps', 'tax_amount', 'line_total_amount',
    ];

    protected static function booted(): void
    {
        $assertDraft = static function (self $line): void {
            $invoice = $line->invoice()->first();

            if ($invoice !== null && $invoice->status !== InvoiceStatus::Draft) {
                throw new LogicException('Invoice lines cannot change after the invoice is issued.');
            }
        };

        self::creating($assertDraft);
        self::updating($assertDraft);
        self::deleting($assertDraft);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity_milli' => 'integer',
            'unit_price_amount' => 'integer',
            'gross_amount' => 'integer',
            'discount_bps' => 'integer',
            'discount_amount' => 'integer',
            'net_amount' => 'integer',
            'tax_bps' => 'integer',
            'tax_amount' => 'integer',
            'line_total_amount' => 'integer',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
