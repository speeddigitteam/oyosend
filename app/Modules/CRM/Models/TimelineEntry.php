<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $event
 * @property string $summary
 * @property array<string, mixed>|null $metadata
 * @property Carbon $occurred_at
 * @property-read TenantMembership|null $actor
 */
class TimelineEntry extends Model
{
    use BelongsToTenant, HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'crm_timeline_entries';

    protected $fillable = ['event', 'summary', 'contact_id', 'company_id', 'lead_id', 'deal_id', 'activity_id', 'actor_id', 'metadata', 'occurred_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime'];
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

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'actor_id');
    }
}
