<?php

declare(strict_types=1);

namespace App\Modules\Communication\Enums;

/** The only two states a channel connection shows. There is no "connected" claim without credentials. */
enum ConfigurationState: string
{
    case Configured = 'configured';
    case NotConfigured = 'not_configured';
}
