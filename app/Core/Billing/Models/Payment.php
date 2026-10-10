<?php

declare(strict_types=1);

namespace App\Core\Billing\Models;

use App\Core\Billing\Enums\PaymentStatus;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to pay an invoice. It only counts once its status is
 * `succeeded`, which is set after the gateway confirmed it to our server, or
 * by an operator recording money received outside a gateway.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property string $gateway
 * @property string|null $method
 * @property PaymentStatus $status
 * @property string $currency
 * @property int $amount
 * @property string $reference
 * @property string|null $gateway_reference
 * @property Carbon|null $paid_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $details
 * @property string|null $recorded_by
 * @property Carbon $created_at
 * @property-read Invoice $invoice
 */
class Payment extends Model
{
    use BelongsToTenant, UsesCentralConnection {
        UsesCentralConnection::getConnectionName insteadof BelongsToTenant;
    }
    use HasUlids;

    /** Gateway key of money received outside any gateway and recorded by an operator. */
    public const string OFFLINE = 'offline';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'invoice_id', 'gateway', 'method', 'status', 'currency', 'amount', 'reference', 'gateway_reference',
        'paid_at', 'failed_at', 'failure_reason', 'details', 'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'details' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function auditLabel(): string
    {
        return $this->reference;
    }
}
