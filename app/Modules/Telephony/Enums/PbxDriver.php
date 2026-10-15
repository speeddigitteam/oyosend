<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Enums;

/**
 * What the workspace's PBX speaks for originating bridge calls. `fake` exists only in the local and testing
 * environments and is never offered anywhere else.
 */
enum PbxDriver: string
{
    case Asterisk = 'asterisk';
    case Fake = 'fake';
}
