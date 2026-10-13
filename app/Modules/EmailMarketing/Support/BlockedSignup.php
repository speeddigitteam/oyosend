<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Support;

use RuntimeException;

/** Internal: aborts the confirmation transaction so a refused address leaves no contact behind. */
final class BlockedSignup extends RuntimeException
{
    public function __construct(public readonly string $listId, string $reason)
    {
        parent::__construct($reason);
    }
}
