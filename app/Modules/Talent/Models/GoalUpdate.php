<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $goal_id
 * @property int $value
 * @property string|null $note
 * @property Carbon $recorded_on
 * @property string|null $recorded_by
 * @property Carbon|null $created_at
 */
class GoalUpdate extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_goal_updates';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['goal_id', 'value', 'note', 'recorded_on', 'recorded_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'recorded_on' => 'date',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('This ledger is append-only.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('This ledger is append-only.');
        });
    }

    /** @return BelongsTo<Goal, $this> */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class, 'goal_id');
    }
}
