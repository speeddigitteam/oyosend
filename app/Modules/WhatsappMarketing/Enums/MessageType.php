<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Enums;

/** The kind of content. Anything this app cannot show is `unsupported`; content is never fetched. */
enum MessageType: string
{
    case Text = 'text';
    case Template = 'template';
    case Image = 'image';
    case Document = 'document';
    case Audio = 'audio';
    case Video = 'video';
    case Sticker = 'sticker';
    case Interactive = 'interactive';
    case Unsupported = 'unsupported';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
