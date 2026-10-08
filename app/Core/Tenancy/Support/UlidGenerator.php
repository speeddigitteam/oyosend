<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Support;

use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

/**
 * Tenant ids are lower-case ULIDs, like every other key in the schema.
 */
final class UlidGenerator implements UniqueIdentifierGenerator
{
    /**
     * @param  mixed  $resource  The model the id is for; unused, an id does not depend on it.
     */
    public static function generate($resource): string
    {
        return strtolower((string) Str::ulid());
    }
}
