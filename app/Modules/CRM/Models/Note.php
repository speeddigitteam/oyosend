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
 * @property string $body
 * @property bool $is_pinned
 * @property string|null $lead_id
 * @property string|null $deal_id
 * @property string|null $activity_id
 * @property string|null $contact_id
 * @property string|null $company_id
 * @property Carbon $created_at
 * @property-read TenantMembership|null $author
 */
class Note extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'crm_notes';

    protected $fillable = ['lead_id', 'deal_id', 'activity_id', 'contact_id', 'company_id', 'author_id', 'body', 'is_pinned'];

    protected function casts(): array
    {
        return ['is_pinned' => 'boolean'];
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

    /** @return BelongsTo<TenantMembership, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'author_id');
    }

    public function auditLabel(): string
    {
        return str($this->body)->limit(80)->toString();
    }
}
