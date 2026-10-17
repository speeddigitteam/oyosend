<?php

declare(strict_types=1);

namespace App\Core\Contacts\Models;

use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\Enums\AddressType;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An invoice, delivery or other address of exactly one company or one contact, with its own person, phone and email
 * (ADR 0039). Written only through {@see ContactDirectory}, which keeps the one-parent rule and one
 * default per type per parent, and audits each change on the parent.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string|null $contact_id
 * @property AddressType $type
 * @property string|null $label
 * @property string|null $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $city
 * @property string|null $postal_code
 * @property string|null $country
 * @property bool $is_default
 * @property Carbon $created_at
 * @property-read Company|null $company
 * @property-read Contact|null $contact
 */
class ContactAddress extends Model
{
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    /** The fields a person fills in; the parent and the default flag are set by ContactDirectory. */
    public const array FIELDS = ['type', 'label', 'name', 'email', 'phone', 'address', 'city', 'postal_code', 'country'];

    /**
     * @var list<string>
     */
    protected $fillable = ['type', 'label', 'name', 'email', 'phone', 'address', 'city', 'postal_code', 'country'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AddressType::class,
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
