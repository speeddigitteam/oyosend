<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\OpeningStatus;
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
 * @property string $pipeline_id
 * @property string|null $hiring_manager_id
 * @property string $title
 * @property string|null $employment_type
 * @property string|null $location
 * @property int $headcount
 * @property OpeningStatus $status
 * @property string|null $description
 * @property string|null $requirements
 * @property Carbon|null $opens_on
 * @property Carbon|null $closes_on
 * @property Carbon|null $opened_at
 * @property Carbon|null $closed_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class JobOpening extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasRecordOwner;
    use HasUlids;

    protected $table = 'talent_job_openings';

    /** @var list<string> */
    protected $fillable = ['owner_id', 'department_id', 'branch_id', 'pipeline_id', 'hiring_manager_id', 'title', 'employment_type', 'location', 'headcount', 'status', 'description', 'requirements', 'opens_on', 'closes_on', 'opened_at', 'closed_at', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'headcount' => 'integer',
            'status' => OpeningStatus::class,
            'opens_on' => 'date',
            'closes_on' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Pipeline, $this> */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }

    /** @return HasMany<Application, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'opening_id');
    }

    public function auditLabel(): string
    {
        return (string) ($this->title);
    }

    public function auditEventName(string $verb): string
    {
        return 'talent.opening.'.$verb;
    }
}
