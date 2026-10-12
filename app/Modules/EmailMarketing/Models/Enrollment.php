<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\EmailMarketing\Enums\EnrollmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One contact's progress through one sequence.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $sequence_id
 * @property string $contact_id
 * @property string|null $subscription_id
 * @property EnrollmentStatus $status
 * @property int $next_position
 * @property Carbon|null $next_due_at
 * @property Carbon $enrolled_at
 * @property Carbon|null $completed_at
 * @property string|null $stopped_reason
 */
class Enrollment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'email_marketing_enrollments';

    /** @var list<string> */
    protected $fillable = [
        'sequence_id', 'contact_id', 'subscription_id', 'status', 'next_position', 'next_due_at', 'enrolled_at',
        'completed_at', 'stopped_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => EnrollmentStatus::class,
            'next_position' => 'integer',
            'next_due_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Sequence, $this> */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class, 'sequence_id');
    }
}
