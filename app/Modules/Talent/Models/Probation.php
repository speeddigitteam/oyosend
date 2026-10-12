<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\ProbationStatus;
use App\Modules\Talent\Models\Concerns\Placed;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $owner_id
 * @property string|null $department_id
 * @property string|null $branch_id
 * @property string $employee_id
 * @property Carbon $started_on
 * @property Carbon $ends_on
 * @property Carbon|null $observed_ends_on
 * @property ProbationStatus $status
 * @property int|null $open_marker
 * @property Carbon|null $closed_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Probation extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;
    use Placed;

    protected $table = 'talent_probations';

    /** @var list<string> */
    protected $fillable = ['owner_id', 'department_id', 'branch_id', 'employee_id', 'started_on', 'ends_on', 'observed_ends_on', 'status', 'open_marker', 'closed_on'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'ends_on' => 'date',
            'observed_ends_on' => 'date',
            'status' => ProbationStatus::class,
            'open_marker' => 'integer',
            'closed_on' => 'date',
        ];
    }

    /** @return HasMany<ProbationReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(ProbationReview::class, 'probation_id')->orderBy('decided_on');
    }

    public function auditLabel(): string
    {
        return (string) ('Probation '.$this->employee_id);
    }

    public function auditEventName(string $verb): string
    {
        return 'talent.probation.'.$verb;
    }
}
