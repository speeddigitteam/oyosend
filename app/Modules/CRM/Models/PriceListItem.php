<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $price_list_id
 * @property string $product_id
 * @property int $minimum_quantity_milli
 * @property int $unit_price_amount
 * @property-read PriceList $priceList
 * @property-read Product $product
 */
class PriceListItem extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'crm_price_list_items';

    /** @var list<string> */
    protected $fillable = ['price_list_id', 'product_id', 'minimum_quantity_milli', 'unit_price_amount'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'minimum_quantity_milli' => 'integer',
            'unit_price_amount' => 'integer',
        ];
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
