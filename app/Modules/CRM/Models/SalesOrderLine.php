<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\CRM\Enums\CatalogItemType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $sales_order_id
 * @property string|null $quote_line_id
 * @property string|null $product_id
 * @property int $position
 * @property CatalogItemType $item_type
 * @property string|null $sku
 * @property string $name
 * @property string $unit
 * @property string|null $description
 * @property int $quantity_milli
 * @property string $currency
 * @property int $unit_price_amount
 * @property int $discount_amount
 * @property int $tax_bps
 * @property int $tax_amount
 * @property int $line_total_amount
 */
class SalesOrderLine extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'crm_sales_order_lines';

    /** @var list<string> */
    protected $fillable = [
        'sales_order_id', 'quote_line_id', 'product_id', 'position', 'item_type', 'sku', 'name', 'unit', 'description',
        'quantity_milli', 'currency', 'unit_price_amount', 'discount_amount', 'tax_bps', 'tax_amount', 'line_total_amount',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Sales order line snapshots are immutable.'));
        self::deleting(static fn (): never => throw new LogicException('Sales order line snapshots are immutable.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'item_type' => CatalogItemType::class,
            'position' => 'integer',
            'quantity_milli' => 'integer',
            'unit_price_amount' => 'integer',
            'discount_amount' => 'integer',
            'tax_bps' => 'integer',
            'tax_amount' => 'integer',
            'line_total_amount' => 'integer',
        ];
    }

    /** @return BelongsTo<SalesOrder, $this> */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /** @return BelongsTo<QuoteLine, $this> */
    public function quoteLine(): BelongsTo
    {
        return $this->belongsTo(QuoteLine::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
