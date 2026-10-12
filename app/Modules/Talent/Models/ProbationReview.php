<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\ProbationDecision;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $probation_id
 * @property ProbationDecision $decision
 * @property Carbon|null $extend_until
 * @property string|null $note
 * @property string|null $decided_by
 * @property Carbon $decided_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProbationReview extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_probation_reviews';

    /** @var list<string> */
    protected $fillable = ['probation_id', 'decision', 'extend_until', 'note', 'decided_by', 'decided_on'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'decision' => ProbationDecision::class,
            'extend_until' => 'date',
            'decided_on' => 'date',
        ];
    }

    /** @return BelongsTo<Probation, $this> */
    public function probation(): BelongsTo
    {
        return $this->belongsTo(Probation::class, 'probation_id');
    }
}
