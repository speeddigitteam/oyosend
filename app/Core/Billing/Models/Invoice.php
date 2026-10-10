<?php

declare(strict_types=1);

namespace App\Core\Billing\Models;

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Enums\InvoiceKind;
use App\Core\Billing\Enums\InvoiceStatus;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What the platform asks a workspace to pay, and what paying it buys: a plan
 * for a period. Not to be confused with the invoices a tenant sends its own
 * customers (the Invoicing app).
 *
 * An invoice is a document: once issued, its lines and amounts do not change.
 * It is paid in full by one payment, or voided and replaced.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $subscription_id
 * @property string $number
 * @property InvoiceKind $kind
 * @property InvoiceStatus $status
 * @property string|null $plan_id
 * @property BillingInterval|null $interval
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property string $currency
 * @property int $subtotal_amount
 * @property int $credit_amount
 * @property int $total_amount
 * @property array<int, array{type: string, plan?: string, interval?: string, amount: int}> $lines
 * @property array<string, string|null>|null $billed_to
 * @property Carbon $issued_at
 * @property Carbon|null $due_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $voided_at
 * @property-read Plan|null $plan
 */
class Invoice extends Model
{
    use BelongsToTenant, UsesCentralConnection {
        UsesCentralConnection::getConnectionName insteadof BelongsToTenant;
    }
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'subscription_id', 'number', 'kind', 'status', 'plan_id', 'interval', 'period_start', 'period_end',
        'currency', 'subtotal_amount', 'credit_amount', 'total_amount', 'lines', 'billed_to',
        'issued_at', 'due_at', 'paid_at', 'voided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => InvoiceKind::class,
            'status' => InvoiceStatus::class,
            'interval' => BillingInterval::class,
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'subtotal_amount' => 'integer',
            'credit_amount' => 'integer',
            'total_amount' => 'integer',
            'lines' => 'array',
            'billed_to' => 'array',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
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
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', InvoiceStatus::Open);
    }

    public function isOpen(): bool
    {
        return $this->status === InvoiceStatus::Open;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->isPast();
    }

    public function auditLabel(): string
    {
        return $this->number;
    }
}
