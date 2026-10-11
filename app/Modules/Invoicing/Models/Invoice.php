<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Audit\Concerns\Auditable;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $contact_id
 * @property string|null $company_id
 * @property string|null $recurring_template_id
 * @property string|null $number
 * @property InvoiceStatus $status
 * @property string $currency
 * @property string|null $title
 * @property string|null $reference
 * @property Carbon|null $issued_on
 * @property Carbon|null $due_on
 * @property int $subtotal_amount
 * @property int $discount_amount
 * @property int $tax_amount
 * @property int $total_amount
 * @property int $paid_amount
 * @property int $credited_amount
 * @property int $balance_amount
 * @property string|null $customer_name
 * @property string|null $customer_contact_name
 * @property string|null $customer_email
 * @property string|null $customer_phone
 * @property string|null $customer_address
 * @property string|null $customer_tax_id
 * @property array<string, mixed>|null $seller_snapshot
 * @property string|null $terms
 * @property string|null $notes
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $source_key
 * @property Carbon|null $issued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $overdue_at
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 * @property Carbon $created_at
 * @property-read Collection<int, InvoiceLine> $lines
 * @property-read Collection<int, Payment> $payments
 * @property-read Collection<int, CreditNote> $creditNotes
 * @property-read Contact|null $contact
 * @property-read Company|null $company
 */
class Invoice extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasRecordOwner;
    use HasUlids;

    /** Commercial facts that are frozen once an invoice leaves draft. */
    private const array FROZEN_AFTER_ISSUE = [
        'contact_id', 'company_id', 'currency', 'number', 'title', 'reference', 'issued_on', 'due_on',
        'subtotal_amount', 'discount_amount', 'tax_amount', 'total_amount',
        'customer_name', 'customer_contact_name', 'customer_email', 'customer_phone', 'customer_address',
        'customer_tax_id', 'seller_snapshot', 'terms', 'notes', 'source_type', 'source_id', 'source_key',
    ];

    protected $table = 'invoicing_invoices';

    /** @var list<string> */
    protected $fillable = [
        'contact_id', 'company_id', 'recurring_template_id', 'number', 'status', 'currency', 'title', 'reference',
        'issued_on', 'due_on', 'subtotal_amount', 'discount_amount', 'tax_amount', 'total_amount', 'paid_amount',
        'credited_amount', 'balance_amount', 'customer_name', 'customer_contact_name', 'customer_email',
        'customer_phone', 'customer_address', 'customer_tax_id', 'seller_snapshot', 'terms', 'notes',
        'source_type', 'source_id', 'source_key', 'issued_at', 'sent_at', 'paid_at', 'overdue_at', 'voided_at',
        'void_reason',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            if ($invoice->getRawOriginal('status') === InvoiceStatus::Draft->value) {
                return;
            }

            foreach (self::FROZEN_AFTER_ISSUE as $attribute) {
                if ($invoice->isDirty($attribute)) {
                    throw new LogicException("The [{$attribute}] of an issued invoice cannot change.");
                }
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issued_on' => 'date',
            'due_on' => 'date',
            'subtotal_amount' => 'integer',
            'discount_amount' => 'integer',
            'tax_amount' => 'integer',
            'total_amount' => 'integer',
            'paid_amount' => 'integer',
            'credited_amount' => 'integer',
            'balance_amount' => 'integer',
            'seller_snapshot' => 'array',
            'issued_at' => 'datetime',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
            'overdue_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('created_at');
    }

    /** @return HasMany<CreditNote, $this> */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class)->orderBy('created_at');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function auditLabel(): string
    {
        return $this->number ?? ($this->title ?? 'Draft invoice');
    }

    public function auditEventName(string $verb): string
    {
        return 'invoicing.invoice.'.$verb;
    }
}
