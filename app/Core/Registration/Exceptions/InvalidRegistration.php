<?php

declare(strict_types=1);

namespace App\Core\Registration\Exceptions;

use RuntimeException;

/**
 * A confirmation link that cannot be used: unknown, expired, or its workspace
 * address was taken in the meantime.
 */
final class InvalidRegistration extends RuntimeException {}
