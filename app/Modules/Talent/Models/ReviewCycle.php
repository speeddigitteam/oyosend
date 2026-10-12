<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\CycleStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $form_id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property Carbon|null $self_due_on
 * @property Carbon|null $manager_due_on
 * @property bool $requires_self_review
 * @property CycleStatus $status
 * @property string|null $department_id
 * @property Carbon|null $launched_at
 * @property Carbon|null $calibration_started_at
 * @property Carbon|null $closed_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReviewCycle extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_review_cycles';

    /** @var list<string> */
    protected $fillable = ['name', 'form_id', 'starts_on', 'ends_on', 'self_due_on', 'manager_due_on', 'requires_self_review', 'status', 'department_id', 'launched_at', 'calibration_started_at', 'closed_at', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'self_due_on' => 'date',
            'manager_due_on' => 'date',
            'requires_self_review' => 'boolean',
            'status' => CycleStatus::class,
            'launched_at' => 'datetime',
            'calibration_started_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ReviewForm, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(ReviewForm::class, 'form_id');
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'cycle_id');
    }

    public function auditLabel(): string
    {
        return (string) ($this->name);
    }

    public function auditEventName(string $verb): string
    {
        return 'talent.review_cycle.'.$verb;
    }
}
