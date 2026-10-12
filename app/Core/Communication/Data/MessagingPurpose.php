<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

/**
 * Why a message is sent. `Marketing` needs a recorded opt-in; `Transactional` is only blocked by the
 * suppression list's `all` scope. The channel gateway sends marketing only.
 */
enum MessagingPurpose: string
{
    case Marketing = 'marketing';
    case Transactional = 'transactional';
}
