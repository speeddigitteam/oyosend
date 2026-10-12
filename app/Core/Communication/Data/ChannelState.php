<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

enum ChannelState: string
{
    case Available = 'available';
    case NotConfigured = 'not_configured';
}
