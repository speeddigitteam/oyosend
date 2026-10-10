<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Audit\Concerns\Auditable;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\CRM\Enums\QuoteKind;
use App\Modules\CRM\Enums\QuoteStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $deal_id
 * @property string|null $contact_id
 * @property string|null $company_id
 * @property string|null $price_list_id
 * @property string $number
 * @property QuoteKind $kind
 * @property string $title
 * @property QuoteStatus $status
 * @property string $currency
 * @property int $probability_percent
 * @property int $subtotal_amount
 * @property int $discount_amount
 * @property int $tax_amount
 * @property int $total_amount
 * @property Carbon|null $valid_until
 * @property string|null $terms
 * @property string|null $notes
 * @property Carbon|null $sent_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $rejected_at
 * @property Carbon $created_at
 * @property-read Collection<int, QuoteLine> $lines
 * @property-read SalesOrder|null $salesOrder
 * @property-read Deal|null $deal
 * @property-read Contact|null $contact
 * @property-read Company|null $company
 * @property-read PriceList|null $priceList
 */
class Quote extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasRecordOwner;
    use HasUlids;

    protected $table = 'crm_quotes';

    /** @var list<string> */
    protected $fillable = [
        'deal_id', 'contact_id', 'company_id', 'price_list_id', 'number', 'kind', 'title', 'status', 'currency',
        'probability_percent', 'subtotal_amount', 'discount_amount', 'tax_amount', 'total_amount', 'valid_until',
        'terms', 'notes', 'sent_at', 'accepted_at', 'rejected_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => QuoteKind::class,
            'status' => QuoteStatus::class,
            'probability_percent' => 'integer',
            'subtotal_amount' => 'integer',
            'discount_amount' => 'integer',
            'tax_amount' => 'integer',
            'total_amount' => 'integer',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /** @return HasMany<QuoteLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('position');
    }

    /** @return HasOne<SalesOrder, $this> */
    public function salesOrder(): HasOne
    {
        return $this->hasOne(SalesOrder::class);
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
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

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    public function auditEventName(string $verb): string
    {
        return 'crm.quote.'.$verb;
    }
}
