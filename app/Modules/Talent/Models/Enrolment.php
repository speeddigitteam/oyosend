<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\EnrolmentStatus;
use App\Modules\Talent\Models\Concerns\Placed;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $owner_id
 * @property string|null $department_id
 * @property string|null $branch_id
 * @property string $course_id
 * @property string|null $session_id
 * @property string $employee_id
 * @property EnrolmentStatus $status
 * @property Carbon $enrolled_on
 * @property Carbon|null $completed_on
 * @property int|null $score
 * @property Carbon|null $expires_on
 * @property int|null $open_marker
 * @property string|null $notes
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Enrolment extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;
    use Placed;

    protected $table = 'talent_enrolments';

    /** @var list<string> */
    protected $fillable = ['owner_id', 'department_id', 'branch_id', 'course_id', 'session_id', 'employee_id', 'status', 'enrolled_on', 'completed_on', 'score', 'expires_on', 'open_marker', 'notes', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => EnrolmentStatus::class,
            'enrolled_on' => 'date',
            'completed_on' => 'date',
            'score' => 'integer',
            'expires_on' => 'date',
            'open_marker' => 'integer',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    /** @return BelongsTo<TrainingSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'session_id');
    }

    public function auditLabel(): string
    {
        return (string) ('Enrolment '.$this->id);
    }

    public function auditEventName(string $verb): string
    {
        return 'talent.enrolment.'.$verb;
    }
}
