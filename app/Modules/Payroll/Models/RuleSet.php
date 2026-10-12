<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Payroll\Enums\RuleBasis;
use App\Modules\Payroll\Enums\RuleCategory;
use App\Modules\Payroll\Enums\RuleMethod;
use App\Modules\Payroll\Enums\RulePayer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A configurable tax / contribution rule: data, never code. The numbers are entered by the
 * workspace; Oyosend ships none. `engine` selects the evaluator (`slab` is built in).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $code
 * @property string|null $country
 * @property string $engine
 * @property RuleCategory $category
 * @property RulePayer $payer
 * @property RuleBasis $basis
 * @property RuleMethod $method
 * @property bool $annualize
 * @property int $periods_per_year
 * @property int|null $min_amount
 * @property int|null $max_amount
 * @property list<string>|null $employment_types
 * @property array<string, mixed>|null $parameters
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property bool $is_active
 * @property int $version
 * @property string|null $notes
 * @property-read Collection<int, RuleSlab> $slabs
 */
class RuleSet extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'payroll_rule_sets';

    /** @var list<string> */
    protected $fillable = [
        'name', 'code', 'country', 'engine', 'category', 'payer', 'basis', 'method', 'annualize', 'periods_per_year',
        'min_amount', 'max_amount', 'employment_types', 'parameters', 'effective_from', 'effective_to', 'is_active',
        'version', 'notes',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'category' => RuleCategory::class,
            'payer' => RulePayer::class,
            'basis' => RuleBasis::class,
            'method' => RuleMethod::class,
            'annualize' => 'boolean',
            'periods_per_year' => 'integer',
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'employment_types' => 'array',
            'parameters' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    /** @return HasMany<RuleSlab, $this> */
    public function slabs(): HasMany
    {
        return $this->hasMany(RuleSlab::class, 'rule_set_id')->orderBy('position');
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'payroll.rule_set.'.$verb;
    }
}
