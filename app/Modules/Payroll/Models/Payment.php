<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Payroll\Enums\PaymentMethod;
use App\Modules\Payroll\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only record of a payment status decision on a payslip. `idempotency_key` is unique per
 * workspace, so a retried request records nothing twice.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $payslip_id
 * @property PaymentStatus $status
 * @property PaymentMethod|null $method
 * @property string|null $reference
 * @property Carbon|null $paid_on
 * @property int $amount
 * @property string $currency
 * @property string $idempotency_key
 * @property string|null $note
 * @property string|null $actor_id
 * @property Carbon|null $created_at
 */
class Payment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'payroll_payments';

    /** @var list<string> */
    protected $fillable = ['payslip_id', 'status', 'method', 'reference', 'paid_on', 'amount', 'currency', 'idempotency_key', 'note', 'actor_id'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['status' => PaymentStatus::class, 'method' => PaymentMethod::class, 'paid_on' => 'date', 'amount' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Payment records are append-only.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Payment records are append-only.');
        });
    }

    /** @return BelongsTo<Payslip, $this> */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
