<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

/** What an inbound WhatsApp message is. Stable strings. */
enum InboundContentKind: string
{
    case Text = 'text';
    /** A tap on one of our reply buttons. */
    case ButtonReply = 'button_reply';
    /** A row chosen from our list message. */
    case ListReply = 'list_reply';
    /** A tap on a quick-reply button of a template. */
    case TemplateButton = 'template_button';
    case Media = 'media';
    case Location = 'location';
    case Reaction = 'reaction';
    /** A cart sent from a catalogue. */
    case Order = 'order';
    /** The submitted form of a WhatsApp Flow. */
    case FlowResponse = 'flow_response';
    case Contacts = 'contacts';
    /** A type this platform does not know or the provider could not deliver. */
    case Unsupported = 'unsupported';
}
