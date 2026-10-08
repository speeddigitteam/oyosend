<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A write tried to place or move a record into a tenant other than the active one.
 */
final class CrossTenantAccessException extends LogicException
{
    public static function writing(Model $model): self
    {
        return new self(sprintf(
            'Refusing to write %s into a tenant other than the active one.',
            $model::class,
        ));
    }

    public static function reassigning(Model $model): self
    {
        return new self(sprintf(
            'The tenant of an existing %s cannot be changed.',
            $model::class,
        ));
    }
}
