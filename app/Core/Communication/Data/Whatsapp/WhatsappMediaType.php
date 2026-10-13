<?php

declare(strict_types=1);

namespace App\Core\Communication\Data\Whatsapp;

enum WhatsappMediaType: string
{
    case Image = 'image';
    case Document = 'document';
    case Audio = 'audio';
    case Video = 'video';
}
