<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Models;

use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Models\Domain as BaseDomain;

/**
 * A hostname that resolves to a tenant.
 *
 * `subdomain` rows hold just the label ("acme"); `custom` rows are reserved
 * for full hostnames once custom domains are built.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $domain
 * @property string $type
 * @property bool $is_primary
 * @property Carbon|null $verified_at
 */
class Domain extends BaseDomain
{
    public const string TYPE_SUBDOMAIN = 'subdomain';

    public const string TYPE_CUSTOM = 'custom';

    protected $table = 'tenant_domains';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }
}
