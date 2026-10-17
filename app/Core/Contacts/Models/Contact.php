<?php

declare(strict_types=1);

namespace App\Core\Contacts\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Audit\Concerns\Auditable;
use App\Core\Communication\Support\PhoneNumber;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A person the workspace deals with. See {@see Company} for how modules use
 * the shared contact records.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $first_name
 * @property string|null $last_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $phone_e164 canonical form of phone; set by the model, never by callers
 * @property string|null $job_title
 * @property string|null $address
 * @property string|null $city
 * @property string|null $country
 * @property string|null $notes
 * @property Carbon $created_at
 * @property-read Company|null $company
 * @property-read Collection<int, ContactAddress> $addresses
 */
#[UseFactory(ContactFactory::class)]
class Contact extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    use HasRecordOwner;
    use HasUlids;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id', 'first_name', 'last_name', 'email', 'phone', 'job_title', 'address', 'city', 'country', 'notes',
    ];

    /** @var list<string> */
    protected $hidden = ['phone_e164'];

    protected static function booted(): void
    {
        // The indexed canonical form of the phone follows it on every write that goes through the model
        // (ContactDirectory, merge, imports): channel lookups use it instead of scanning raw phones.
        static::saving(static function (Contact $contact): void {
            if (! $contact->exists || $contact->isDirty('phone')) {
                $contact->phone_e164 = PhoneNumber::normalize($contact->phone);
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Its invoice, delivery and other addresses (ADR 0039).
     *
     * @return HasMany<ContactAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(ContactAddress::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }

    public function auditLabel(): string
    {
        return $this->fullName();
    }
}
