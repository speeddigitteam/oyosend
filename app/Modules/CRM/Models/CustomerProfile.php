<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Audit\Concerns\Auditable;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\CRM\Enums\CustomerLifecycleStage;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $contact_id
 * @property string|null $company_id
 * @property CustomerLifecycleStage $stage
 * @property array<string, int>|null $lifetime_values
 * @property Carbon|null $first_won_at
 * @property Carbon|null $last_won_at
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $next_follow_up_at
 * @property-read Contact|null $contact
 * @property-read Company|null $company
 */
class CustomerProfile extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasRecordOwner;
    use HasUlids;

    protected $table = 'crm_customer_profiles';

    /** @var list<string> */
    protected $fillable = [
        'contact_id', 'company_id', 'stage', 'lifetime_values', 'first_won_at', 'last_won_at',
        'last_activity_at', 'next_follow_up_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'stage' => CustomerLifecycleStage::class,
            'lifetime_values' => 'array',
            'first_won_at' => 'datetime',
            'last_won_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
        ];
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
        if ($this->contact !== null) {
            return $this->contact->fullName();
        }

        return $this->company === null ? $this->id : $this->company->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'crm.customer_profile.'.$verb;
    }
}
