<?php

declare(strict_types=1);

namespace App\Core\AppStore\Enums;

enum AppSettingType: string
{
    case Text = 'text';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Select = 'select';
    case Secret = 'secret';
}
