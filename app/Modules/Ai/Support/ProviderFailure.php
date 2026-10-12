<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use RuntimeException;

/**
 * The provider could not answer. The message is generic by construction: it never carries the
 * credential, the request or the response body.
 */
final class ProviderFailure extends RuntimeException {}
