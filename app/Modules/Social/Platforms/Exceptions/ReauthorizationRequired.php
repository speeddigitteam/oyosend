<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Exceptions;

use RuntimeException;

/** The platform no longer accepts the stored authorisation; the person must connect the account again. */
final class ReauthorizationRequired extends RuntimeException {}
