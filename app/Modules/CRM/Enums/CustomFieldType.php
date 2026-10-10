<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum CustomFieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';
    case Date = 'date';
    case Select = 'select';
}
