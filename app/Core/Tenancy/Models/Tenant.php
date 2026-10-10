<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Models;

use App\Core\AppStore\Models\SubscriptionItem;
use App\Core\Billing\Models\Subscription;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * A customer workspace. Central (platform) data: it is never tenant-scoped.
 *
 * @property string $id
 * @property string $name
 * @property string|null $legal_name
 * @property string $slug
 * @property TenantStatus $status
 * @property string $locale
 * @property string $timezone
 * @property string $currency
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $website
 * @property string|null $address
 * @property string|null $city
 * @property string|null $postal_code
 * @property string|null $country
 * @property string|null $tax_id
 * @property string|null $logo_path
 * @property string|null $db_connection
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $onboarded_at
 * @property Carbon $created_at
 * @property-read Subscription|null $subscription
 * @property Carbon|null $deleted_at
 */
#[UseFactory(TenantFactory::class)]
class Tenant extends BaseTenant
{
    use HasDomains;

    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'tenants';

    /**
     * Real columns. Any other attribute is stored in the JSON `data` column.
     *
     * @return list<string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'legal_name',
            'slug',
            'status',
            'locale',
            'timezone',
            'currency',
            'email',
            'phone',
            'website',
            'address',
            'city',
            'postal_code',
            'country',
            'tax_id',
            'logo_path',
            'db_connection',
            'trial_ends_at',
            'suspended_at',
            'onboarded_at',
            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'trial_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'onboarded_at' => 'datetime',
        ];
    }

    /**
     * Memberships of this tenant, whatever tenant context is active.
     *
     * @return HasMany<TenantMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class)->withoutGlobalScopes();
    }

    /**
     * The tenant's subscription, whatever tenant context is active.
     *
     * @return HasOne<Subscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->withoutGlobalScopes();
    }

    /** @return HasMany<SubscriptionItem, $this> */
    public function subscriptionItems(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class)->withoutGlobalScopes();
    }

    public function isOnboarded(): bool
    {
        return $this->onboarded_at !== null;
    }

    public function isAccessible(): bool
    {
        return $this->status->allowsAccess();
    }
}
