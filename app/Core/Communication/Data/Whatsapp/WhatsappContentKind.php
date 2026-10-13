<?php

declare(strict_types=1);

namespace App\Core\Communication\Data\Whatsapp;

/** The shape of a rich WhatsApp message. Stable strings (stored in the outbox). */
enum WhatsappContentKind: string
{
    /** An approved template, with header media and button parameters. Allowed at any time. */
    case Template = 'template';
    /** Up to 3 reply buttons. */
    case Buttons = 'buttons';
    /** A list message: up to 10 rows in up to 10 sections. */
    case List = 'list';
    /** A single call-to-action URL button. */
    case CtaUrl = 'cta_url';
    /** An image, document, audio or video message. */
    case Media = 'media';
    /** One product of a catalogue. */
    case Product = 'product';
    /** Up to 30 products of a catalogue in up to 10 sections. */
    case ProductList = 'product_list';
    /** A WhatsApp Flow. */
    case Flow = 'flow';

    /** Everything except a template is only valid inside the customer's open 24-hour service window. */
    public function requiresOpenWindow(): bool
    {
        return $this !== self::Template;
    }
}
