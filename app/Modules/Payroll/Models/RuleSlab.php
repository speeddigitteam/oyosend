<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered band of a rule set: `from_amount` (inclusive) up to `to_amount` (exclusive, null = no upper end).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $rule_set_id
 * @property int $position
 * @property int $from_amount
 * @property int|null $to_amount
 * @property int $rate_bps
 * @property int $fixed_amount
 */
class RuleSlab extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'payroll_rule_slabs';

    /** @var list<string> */
    protected $fillable = ['rule_set_id', 'position', 'from_amount', 'to_amount', 'rate_bps', 'fixed_amount'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer', 'from_amount' => 'integer', 'to_amount' => 'integer', 'rate_bps' => 'integer', 'fixed_amount' => 'integer'];
    }

    /** @return BelongsTo<RuleSet, $this> */
    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class, 'rule_set_id');
    }
}
