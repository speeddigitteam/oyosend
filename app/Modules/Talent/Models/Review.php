<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\ReviewStatus;
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
 * @property string $cycle_id
 * @property string $employee_id
 * @property string|null $reviewer_employee_id
 * @property ReviewStatus $status
 * @property int|null $self_score
 * @property int|null $manager_score
 * @property int|null $final_score
 * @property bool $final_overridden
 * @property string|null $self_comment
 * @property string|null $manager_comment
 * @property string|null $calibration_note
 * @property string|null $employee_comment
 * @property Carbon|null $self_submitted_at
 * @property Carbon|null $manager_submitted_at
 * @property Carbon|null $calibrated_at
 * @property string|null $calibrated_by
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Review extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;
    use Placed;

    protected $table = 'talent_reviews';

    /** @var list<string> */
    protected $fillable = ['owner_id', 'department_id', 'branch_id', 'cycle_id', 'employee_id', 'reviewer_employee_id', 'status', 'self_score', 'manager_score', 'final_score', 'final_overridden', 'self_comment', 'manager_comment', 'calibration_note', 'employee_comment', 'self_submitted_at', 'manager_submitted_at', 'calibrated_at', 'calibrated_by', 'acknowledged_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'self_score' => 'integer',
            'manager_score' => 'integer',
            'final_score' => 'integer',
            'final_overridden' => 'boolean',
            'self_submitted_at' => 'datetime',
            'manager_submitted_at' => 'datetime',
            'calibrated_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ReviewCycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class, 'cycle_id');
    }

    /** @return HasMany<ReviewRating, $this> */
    public function ratings(): HasMany
    {
        return $this->hasMany(ReviewRating::class, 'review_id');
    }

    /** @return list<string> */
    protected function auditExcluded(): array
    {
        return ['self_comment', 'manager_comment', 'calibration_note', 'employee_comment'];
    }

    public function auditLabel(): string
    {
        return (string) ('Review '.$this->id);
    }

    public function auditEventName(string $verb): string
    {
        return 'talent.review.'.$verb;
    }
}
