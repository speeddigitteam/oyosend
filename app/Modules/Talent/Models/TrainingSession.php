<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $course_id
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property string $timezone
 * @property string|null $location
 * @property string|null $trainer
 * @property int|null $capacity
 * @property SessionStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TrainingSession extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_training_sessions';

    /** @var list<string> */
    protected $fillable = ['course_id', 'starts_at', 'ends_at', 'timezone', 'location', 'trainer', 'capacity', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'status' => SessionStatus::class,
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function auditLabel(): string
    {
        return (string) ('Session '.$this->id);
    }

    public function auditEventName(string $verb): string
    {
        return 'talent.session.'.$verb;
    }
}
