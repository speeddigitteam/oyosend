<?php

declare(strict_types=1);

namespace App\Core\Access\Models;

use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * A capability registered by a module (for example `crm.leads.edit`).
 *
 * Permissions are global reference data: the catalogue is the same for every
 * tenant and is written only by `php artisan permissions:sync`. What differs
 * per tenant is which roles hold them.
 *
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property string $module
 */
class Permission extends SpatiePermission
{
    use UsesCentralConnection;
}
