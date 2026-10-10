<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Audit\Concerns\Auditable;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\CRM\Enums\ContractStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $sales_order_id
 * @property string|null $contact_id
 * @property string|null $company_id
 * @property string $number
 * @property string $title
 * @property ContractStatus $status
 * @property string $currency
 * @property int $value_amount
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property Carbon|null $renews_on
 * @property Carbon|null $signed_at
 * @property Carbon|null $terminated_at
 * @property string|null $terms
 * @property-read SalesOrder|null $salesOrder
 * @property-read Contact|null $contact
 * @property-read Company|null $company
 */
class Contract extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasRecordOwner;
    use HasUlids;

    protected $table = 'crm_contracts';

    /** @var list<string> */
    protected $fillable = [
        'sales_order_id', 'contact_id', 'company_id', 'number', 'title', 'status', 'currency', 'value_amount',
        'starts_on', 'ends_on', 'renews_on', 'signed_at', 'terminated_at', 'terms',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ContractStatus::class,
            'value_amount' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'renews_on' => 'date',
            'signed_at' => 'datetime',
            'terminated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SalesOrder, $this> */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
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
        return 'crm.contract.'.$verb;
    }
}
