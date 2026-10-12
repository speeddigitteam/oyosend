<?php

declare(strict_types=1);

namespace App\Modules\Support\Enums;

enum TicketChannel: string
{
    case Web = 'web';
    case Email = 'email';
    case Phone = 'phone';
    case Chat = 'chat';
    case Api = 'api';
    case Internal = 'internal';
}
