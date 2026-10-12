<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Exceptions;

use RuntimeException;

/** A webhook whose signature is missing, malformed, stale or wrong. */
final class InvalidWebhookSignature extends RuntimeException {}
