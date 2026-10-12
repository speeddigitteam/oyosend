<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

/** What a provider told the communication platform. Stable strings. */
enum ChannelEventType: string
{
    /** The provider confirmed delivery of an outbound message. */
    case Delivered = 'delivered';
    /** The recipient read an outbound message (where the provider reports it). */
    case Read = 'read';
    case Failed = 'failed';
    case Bounced = 'bounced';
    case Complained = 'complained';
    /** A customer sent a message to the business. */
    case Inbound = 'inbound';
    /** The provider changed the approval status of a message template (WhatsApp). */
    case TemplateStatus = 'template_status';
}
