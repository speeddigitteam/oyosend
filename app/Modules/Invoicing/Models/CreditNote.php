<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property string|null $issued_by
 * @property string $number
 * @property string $currency
 * @property Carbon $issued_on
 * @property string $reason
 * @property int $subtotal_amount
 * @property int $discount_amount
 * @property int $tax_amount
 * @property int $total_amount
 * @property Carbon $created_at
 * @property-read Collection<int, CreditNoteLine> $lines
 * @property-read Invoice $invoice
 */
class CreditNote extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'invoicing_credit_notes';

    /** @var list<string> */
    protected $fillable = [
        'invoice_id', 'issued_by', 'number', 'currency', 'issued_on', 'reason', 'subtotal_amount',
        'discount_amount', 'tax_amount', 'total_amount',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'subtotal_amount' => 'integer',
            'discount_amount' => 'integer',
            'tax_amount' => 'integer',
            'total_amount' => 'integer',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return HasMany<CreditNoteLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class)->orderBy('position');
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    public function auditEventName(string $verb): string
    {
        return 'invoicing.credit_note.'.$verb;
    }
}
