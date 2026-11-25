<?php

declare(strict_types=1);

namespace App\Core\Billing\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Billing\Enums\BillingInterval;
use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Carbon\CarbonInterface;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
 * @property int|null $offer_monthly_amount
 * @property int|null $offer_yearly_amount
 * @property Carbon|null $offer_ends_at
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
        'key', 'name', 'name_bn', 'description', 'description_bn', 'currency', 'monthly_amount', 'yearly_amount',
        'offer_monthly_amount', 'offer_yearly_amount', 'offer_ends_at', 'trial_days',
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
            'offer_monthly_amount' => 'integer',
            'offer_yearly_amount' => 'integer',
            'offer_ends_at' => 'datetime',
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
     * The price for one period today: the offer's while one runs for that interval, else the list price. Null when the
     * plan is not sold for that interval. Whatever a workspace takes the plan at, it keeps (`subscriptions.amount`).
     */
    public function amountFor(BillingInterval $interval): ?int
    {
        return $this->offerAmountFor($interval) ?? $this->listAmountFor($interval);
    }

    /**
     * The plan's own price for one period, offer or not.
     */
    public function listAmountFor(BillingInterval $interval): ?int
    {
        return match ($interval) {
            BillingInterval::Monthly => $this->monthly_amount,
            BillingInterval::Yearly => $this->yearly_amount,
        };
    }

    /**
     * The offer's price for one period while the offer runs, or null. An offer below nothing, or not below the list
     * price, is no offer.
     */
    public function offerAmountFor(BillingInterval $interval, ?CarbonInterface $at = null): ?int
    {
        if (! $this->hasLiveOffer($at)) {
            return null;
        }

        $offer = match ($interval) {
            BillingInterval::Monthly => $this->offer_monthly_amount,
            BillingInterval::Yearly => $this->offer_yearly_amount,
        };
        $list = $this->listAmountFor($interval);

        return $offer !== null && $offer > 0 && $list !== null && $offer < $list ? $offer : null;
    }

    /**
     * An offer that has not ended yet, for at least one interval.
     */
    public function hasLiveOffer(?CarbonInterface $at = null): bool
    {
        return $this->offer_ends_at !== null
            && $this->offer_ends_at->isAfter($at ?? Carbon::now())
            && ($this->offer_monthly_amount !== null || $this->offer_yearly_amount !== null);
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
