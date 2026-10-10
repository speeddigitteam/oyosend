<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Audit\Concerns\Auditable;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\CRM\Enums\SalesOrderStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $quote_id
 * @property string|null $contact_id
 * @property string|null $company_id
 * @property string $number
 * @property SalesOrderStatus $status
 * @property string $currency
 * @property int $subtotal_amount
 * @property int $discount_amount
 * @property int $tax_amount
 * @property int $total_amount
 * @property string|null $terms
 * @property string|null $notes
 * @property Carbon $ordered_at
 * @property Carbon|null $fulfilled_at
 * @property Carbon|null $canceled_at
 * @property-read Quote|null $quote
 * @property-read Collection<int, SalesOrderLine> $lines
 * @property-read Contact|null $contact
 * @property-read Company|null $company
 */
class SalesOrder extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasRecordOwner;
    use HasUlids;

    protected $table = 'crm_sales_orders';

    /** @var list<string> */
    protected $fillable = [
        'quote_id', 'contact_id', 'company_id', 'number', 'status', 'currency', 'subtotal_amount', 'discount_amount',
        'tax_amount', 'total_amount', 'terms', 'notes', 'ordered_at', 'fulfilled_at', 'canceled_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SalesOrderStatus::class,
            'subtotal_amount' => 'integer',
            'discount_amount' => 'integer',
            'tax_amount' => 'integer',
            'total_amount' => 'integer',
            'ordered_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Quote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /** @return HasMany<SalesOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('position');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    public function auditEventName(string $verb): string
    {
        return 'crm.sales_order.'.$verb;
    }
}
