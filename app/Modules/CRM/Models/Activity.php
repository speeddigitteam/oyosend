<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\CRM\Enums\ActivityPriority;
use App\Modules\CRM\Enums\ActivityStatus;
use App\Modules\CRM\Enums\ActivityType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ActivityType $type
 * @property ActivityStatus $status
 * @property ActivityPriority $priority
 * @property string $subject
 * @property string|null $description
 * @property string|null $lead_id
 * @property string|null $deal_id
 * @property string|null $contact_id
 * @property string|null $company_id
 * @property Carbon|null $starts_at
 * @property Carbon|null $due_at
 * @property Carbon|null $remind_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property-read Lead|null $lead
 * @property-read Deal|null $deal
 * @property-read Contact|null $contact
 * @property-read Company|null $company
 * @property-read TenantMembership|null $owner
 */
class Activity extends Model
{
    use BelongsToTenant, HasRecordOwner, HasUlids;

    protected $table = 'crm_activities';

    protected $fillable = ['lead_id', 'deal_id', 'contact_id', 'company_id', 'type', 'status', 'priority', 'subject', 'description', 'starts_at', 'due_at', 'remind_at', 'completed_at', 'automation_key'];

    protected $hidden = ['automation_key'];

    protected function casts(): array
    {
        return ['type' => ActivityType::class, 'status' => ActivityStatus::class, 'priority' => ActivityPriority::class, 'starts_at' => 'datetime', 'due_at' => 'datetime', 'remind_at' => 'datetime', 'completed_at' => 'datetime'];
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
        return $this->subject;
    }
}
