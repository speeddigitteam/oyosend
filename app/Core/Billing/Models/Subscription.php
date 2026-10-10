<?php

declare(strict_types=1);

namespace App\Core\Billing\Models;

use App\Core\Billing\BillingCycle;
use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Enums\SubscriptionStatus;
use App\Core\Billing\SubscriptionManager;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The plan a workspace is on and where it stands with paying for it. One per
 * tenant. It is changed only by {@see SubscriptionManager}
 * and {@see BillingCycle}.
 *
 * Central data scoped to its tenant: a workspace only ever sees its own, and
 * the Super Admin panel looks across tenants explicitly.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $plan_id
 * @property SubscriptionStatus $status
 * @property BillingInterval $interval
 * @property string $currency
 * @property int $amount
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $grace_ends_at
 * @property bool $cancel_at_period_end
 * @property Carbon|null $canceled_at
 * @property Carbon|null $ended_at
 * @property string|null $pending_plan_id
 * @property BillingInterval|null $pending_interval
 * @property Carbon $created_at
 * @property-read Plan $plan
 * @property-read Plan|null $pendingPlan
 */
class Subscription extends Model
{
    use BelongsToTenant, UsesCentralConnection {
        UsesCentralConnection::getConnectionName insteadof BelongsToTenant;
    }
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'plan_id', 'status', 'interval', 'currency', 'amount', 'trial_ends_at', 'current_period_start',
        'current_period_end', 'grace_ends_at', 'cancel_at_period_end', 'canceled_at', 'ended_at',
        'pending_plan_id', 'pending_interval',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'interval' => BillingInterval::class,
            'pending_interval' => BillingInterval::class,
            'amount' => 'integer',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_ends_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'canceled_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function grantsAccess(): bool
    {
        return $this->status->grantsAccess();
    }

    /**
     * Ended: the workspace is locked down to billing until a plan is taken again.
     */
    public function isLocked(): bool
    {
        return ! $this->grantsAccess();
    }

    /**
     * Nothing is ever charged for it: no period, no renewal.
     */
    public function isFree(): bool
    {
        return $this->amount === 0;
    }

    /**
     * Within a period that has been paid for.
     */
    public function isInPaidPeriod(): bool
    {
        return $this->status === SubscriptionStatus::Active
            && ! $this->isFree()
            && $this->current_period_end !== null
            && $this->current_period_end->isFuture();
    }

    public function auditLabel(): string
    {
        return $this->relationLoaded('plan') ? $this->plan->name : (string) $this->plan()->value('name');
    }
}
