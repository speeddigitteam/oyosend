<?php

declare(strict_types=1);

namespace App\Core\Contacts\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An organisation the workspace deals with: a customer, a supplier, a partner.
 *
 * Together with {@see Contact} this is the one shared customer identity of a
 * workspace. Modules attach their own records to it by `company_id`; they do
 * not keep customer tables of their own.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $website
 * @property string|null $industry
 * @property string|null $tax_id
 * @property string|null $address
 * @property string|null $city
 * @property string|null $country
 * @property string|null $notes
 * @property Carbon $created_at
 * @property-read Collection<int, ContactAddress> $addresses
 */
#[UseFactory(CompanyFactory::class)]
class Company extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    use HasRecordOwner;
    use HasUlids;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name', 'email', 'phone', 'website', 'industry', 'tax_id', 'address', 'city', 'country', 'notes',
    ];

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
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
}
