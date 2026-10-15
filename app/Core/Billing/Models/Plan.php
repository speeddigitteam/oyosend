<?php

declare(strict_types=1);

namespace App\Core\Billing\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Billing\Enums\BillingInterval;
use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a workspace can subscribe to: a price per interval, the optional
 * modules it includes and the limits it sets. Platform data, the same for
 * every tenant; managed from the Super Admin panel.
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string|null $name_bn
 * @property string|null $description
 * @property string|null $description_bn
 * @property string $currency
 * @property int|null $monthly_amount
 * @property int|null $yearly_amount
 * @property int $trial_days
 * @property array<int, string> $modules
 * @property array<string, int> $limits
 * @property bool $is_public
 * @property bool $is_active
 * @property int $sort_order
 */
#[UseFactory(PlanFactory::class)]
class Plan extends Model
{
    use Auditable;

    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    use HasUlids;
    use UsesCentralConnection;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key', 'name', 'name_bn', 'description', 'description_bn', 'currency', 'monthly_amount', 'yearly_amount', 'trial_days',
        'modules', 'limits', 'is_public', 'is_active', 'sort_order',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'modules' => '[]',
        'limits' => '{}',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monthly_amount' => 'integer',
            'yearly_amount' => 'integer',
            'trial_days' => 'integer',
            'modules' => 'array',
            'limits' => 'array',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->withoutGlobalScopes();
    }

    /**
     * Plans a workspace may choose for itself.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOffered(Builder $query): void
    {
        $query->where('is_active', true)->where('is_public', true)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * The price for one period, or null when the plan is not sold for that interval.
     */
    public function amountFor(BillingInterval $interval): ?int
    {
        return match ($interval) {
            BillingInterval::Monthly => $this->monthly_amount,
            BillingInterval::Yearly => $this->yearly_amount,
        };
    }

    public function offers(BillingInterval $interval): bool
    {
        return $this->amountFor($interval) !== null;
    }

    /**
     * @return list<BillingInterval>
     */
    public function intervals(): array
    {
        return array_values(array_filter(BillingInterval::cases(), $this->offers(...)));
    }

    /**
     * A plan nobody ever pays for: every interval it is sold for costs nothing.
     */
    /**
     * The name in a language: the Bengali one on Bengali pages when the operator wrote one, else the name.
     */
    public function nameIn(string $locale): string
    {
        return $locale === 'bn' && filled($this->name_bn) ? (string) $this->name_bn : $this->name;
    }

    public function descriptionIn(string $locale): ?string
    {
        return $locale === 'bn' && filled($this->description_bn) ? (string) $this->description_bn : $this->description;
    }

    public function isFree(): bool
    {
        return ($this->monthly_amount ?? 0) === 0 && ($this->yearly_amount ?? 0) === 0;
    }

    /**
     * The maximum the plan sets for a limit, or null for unlimited.
     */
    public function limit(string $key): ?int
    {
        $value = $this->limits[$key] ?? null;

        return $value === null ? null : (int) $value;
    }
}
