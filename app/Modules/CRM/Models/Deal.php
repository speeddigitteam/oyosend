<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\CRM\Enums\DealStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $pipeline_id
 * @property string $stage_id
 * @property string|null $lead_id
 * @property string|null $contact_id
 * @property string|null $company_id
 * @property string|null $owner_id
 * @property string $title
 * @property DealStatus $status
 * @property string $currency
 * @property int $amount
 * @property Carbon|null $expected_close_date
 * @property Carbon|null $won_at
 * @property Carbon|null $lost_at
 * @property string|null $lost_reason
 * @property string|null $description
 * @property Carbon $created_at
 * @property-read Pipeline $pipeline
 * @property-read PipelineStage $stage
 * @property-read Lead|null $lead
 * @property-read Contact|null $contact
 * @property-read Company|null $company
 * @property-read TenantMembership|null $owner
 * @property-read Collection<int, DealStageHistory> $stageHistory
 */
class Deal extends Model
{
    use BelongsToTenant, HasRecordOwner, HasUlids;

    protected $table = 'crm_deals';

    protected $fillable = ['pipeline_id', 'stage_id', 'lead_id', 'contact_id', 'company_id', 'title', 'status', 'currency', 'amount', 'expected_close_date', 'won_at', 'lost_at', 'lost_reason', 'description'];

    protected function casts(): array
    {
        return ['status' => DealStatus::class, 'amount' => 'integer', 'expected_close_date' => 'date', 'won_at' => 'datetime', 'lost_at' => 'datetime'];
    }

    /** @return BelongsTo<Pipeline, $this> */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    /** @return BelongsTo<PipelineStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
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

    /** @return HasMany<DealStageHistory, $this> */
    public function stageHistory(): HasMany
    {
        return $this->hasMany(DealStageHistory::class)->orderByDesc('changed_at');
    }

    public function auditLabel(): string
    {
        return $this->title;
    }
}
