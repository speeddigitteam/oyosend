<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Exceptions;

use RuntimeException;

/** The platform cannot be used in this build or configuration. The message is the reason shown to people. */
final class PlatformUnavailable extends RuntimeException {}
