<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\InterviewMode;
use App\Modules\Talent\Enums\InterviewOutcome;
use App\Modules\Talent\Enums\InterviewStatus;
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
 * @property string $application_id
 * @property string $round_name
 * @property InterviewMode $mode
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $timezone
 * @property string|null $location
 * @property InterviewStatus $status
 * @property InterviewOutcome|null $outcome
 * @property string|null $notes
 * @property Carbon|null $completed_at
 * @property string|null $scheduled_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Interview extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;
    use Placed;

    protected $table = 'talent_interviews';

    /** @var list<string> */
    protected $fillable = ['owner_id', 'department_id', 'branch_id', 'application_id', 'round_name', 'mode', 'starts_at', 'ends_at', 'timezone', 'location', 'status', 'outcome', 'notes', 'completed_at', 'scheduled_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mode' => InterviewMode::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => InterviewStatus::class,
            'outcome' => InterviewOutcome::class,
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'application_id');
    }

    /** @return HasMany<InterviewPanelist, $this> */
    public function panel(): HasMany
    {
        return $this->hasMany(InterviewPanelist::class, 'interview_id');
    }

    /** @return HasMany<Scorecard, $this> */
    public function scorecards(): HasMany
    {
        return $this->hasMany(Scorecard::class, 'interview_id');
    }

    public function auditLabel(): string
    {
        return (string) ($this->round_name);
    }

    public function auditEventName(string $verb): string
    {
        return 'talent.interview.'.$verb;
    }
}
