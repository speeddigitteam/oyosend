<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Pbx;

use RuntimeException;

/** The PBX could not be asked. The message is safe to show: it never carries a URL, a secret or a PBX answer. */
final class PbxUnavailable extends RuntimeException {}
