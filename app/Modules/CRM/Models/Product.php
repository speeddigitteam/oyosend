<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\CRM\Enums\CatalogItemType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property CatalogItemType $type
 * @property string|null $sku
 * @property string $name
 * @property string|null $description
 * @property string $unit
 * @property string $currency
 * @property int $unit_price_amount
 * @property int $tax_bps
 * @property bool $is_active
 * @property Carbon $created_at
 */
class Product extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'crm_products';

    /** @var list<string> */
    protected $fillable = [
        'type', 'sku', 'name', 'description', 'unit', 'currency', 'unit_price_amount', 'tax_bps', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CatalogItemType::class,
            'unit_price_amount' => 'integer',
            'tax_bps' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<PriceListItem, $this> */
    public function priceListItems(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'crm.product.'.$verb;
    }
}
