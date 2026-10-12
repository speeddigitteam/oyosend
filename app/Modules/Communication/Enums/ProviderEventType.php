<?php

declare(strict_types=1);

namespace App\Modules\Communication\Enums;

enum ProviderEventType: string
{
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Inbound = 'inbound';
    /** A recipient read an outbound message. Forwarded to channel-event listeners; changes no message state. */
    case Read = 'read';
    /** A provider changed a message template's approval status. Forwarded to listeners only. */
    case TemplateStatus = 'template_status';
}
