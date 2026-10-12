<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\ApplicationStatus;
use App\Modules\Talent\Models\Concerns\Placed;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $owner_id
 * @property string|null $department_id
 * @property string|null $branch_id
 * @property string $opening_id
 * @property string $candidate_id
 * @property string $stage_id
 * @property ApplicationStatus $status
 * @property string|null $rejection_reason_id
 * @property string|null $rejection_note
 * @property string|null $source
 * @property Carbon $applied_on
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $hired_at
 * @property Carbon|null $start_on
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Application extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;
    use Placed;

    protected $table = 'talent_applications';

    /** @var list<string> */
    protected $fillable = ['owner_id', 'department_id', 'branch_id', 'opening_id', 'candidate_id', 'stage_id', 'status', 'rejection_reason_id', 'rejection_note', 'source', 'applied_on', 'status_changed_at', 'hired_at', 'start_on', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'applied_on' => 'date',
            'status_changed_at' => 'datetime',
            'hired_at' => 'datetime',
            'start_on' => 'date',
        ];
    }

    /** @return BelongsTo<JobOpening, $this> */
    public function opening(): BelongsTo
    {
        return $this->belongsTo(JobOpening::class, 'opening_id');
    }

    /** @return BelongsTo<Candidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }

    /** @return BelongsTo<PipelineStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    /** @return BelongsTo<RejectionReason, $this> */
    public function rejectionReason(): BelongsTo
    {
        return $this->belongsTo(RejectionReason::class, 'rejection_reason_id');
    }

    /** @return HasMany<ApplicationHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(ApplicationHistory::class, 'application_id')->orderBy('created_at');
    }

    /** @return HasMany<Interview, $this> */
    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class, 'application_id')->orderBy('starts_at');
    }

    /** @return HasMany<Offer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class, 'application_id');
    }

    /** @return HasMany<Note, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class, 'application_id')->orderByDesc('created_at');
    }

    public function auditLabel(): string
    {
        return (string) ('Application '.$this->id);
    }

    public function auditEventName(string $verb): string
    {
        return 'talent.application.'.$verb;
    }
}
