<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\CRM\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $title
 * @property string|null $contact_id
 * @property string|null $company_id
 * @property string|null $source_id
 * @property string|null $owner_id
 * @property LeadStatus $status
 * @property int $score
 * @property string|null $qualification_notes
 * @property Carbon|null $qualified_at
 * @property Carbon|null $converted_at
 * @property Carbon $created_at
 * @property-read Contact|null $contact
 * @property-read Company|null $company
 * @property-read LeadSource|null $source
 * @property-read TenantMembership|null $owner
 * @property-read Collection<int, Deal> $deals
 */
class Lead extends Model
{
    use BelongsToTenant, HasRecordOwner, HasUlids;

    protected $table = 'crm_leads';

    protected $fillable = ['contact_id', 'company_id', 'source_id', 'title', 'status', 'score', 'qualification_notes', 'qualified_at', 'converted_at'];

    protected function casts(): array
    {
        return ['status' => LeadStatus::class, 'score' => 'integer', 'qualified_at' => 'datetime', 'converted_at' => 'datetime'];
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

    /** @return BelongsTo<LeadSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    /** @return HasMany<Deal, $this> */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function auditLabel(): string
    {
        return $this->title;
    }
}
