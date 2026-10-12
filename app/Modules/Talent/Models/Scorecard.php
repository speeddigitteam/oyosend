<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\Recommendation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $interview_id
 * @property string $application_id
 * @property string $employee_id
 * @property int $rating
 * @property Recommendation $recommendation
 * @property string|null $strengths
 * @property string|null $concerns
 * @property Carbon $submitted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Scorecard extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_scorecards';

    /** @var list<string> */
    protected $fillable = ['interview_id', 'application_id', 'employee_id', 'rating', 'recommendation', 'strengths', 'concerns', 'submitted_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'recommendation' => Recommendation::class,
            'submitted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Interview, $this> */
    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class, 'interview_id');
    }
}
