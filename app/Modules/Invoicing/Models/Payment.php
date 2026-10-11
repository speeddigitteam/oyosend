<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Invoicing\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A payment recorded by a member against an invoice. Never confirmed by a gateway.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property string|null $recorded_by
 * @property string|null $reversed_by
 * @property int $amount
 * @property string $currency
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property Carbon $paid_on
 * @property string|null $notes
 * @property string|null $idempotency_key
 * @property Carbon|null $reversed_at
 * @property string|null $reversal_reason
 * @property Carbon $created_at
 */
class Payment extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'invoicing_payments';

    /** @var list<string> */
    protected $fillable = [
        'invoice_id', 'recorded_by', 'reversed_by', 'amount', 'currency', 'method', 'reference', 'paid_on',
        'notes', 'idempotency_key', 'reversed_at', 'reversal_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'method' => PaymentMethod::class,
            'paid_on' => 'date',
            'reversed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'recorded_by');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function auditLabel(): string
    {
        return $this->reference ?? $this->method->value;
    }

    public function auditEventName(string $verb): string
    {
        return 'invoicing.payment.'.$verb;
    }
}
