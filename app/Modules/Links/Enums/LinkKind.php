<?php

declare(strict_types=1);

namespace App\Modules\Links\Enums;

/** What a saved link points at. `Email` holds an email address; every other kind holds an http(s) URL. */
enum LinkKind: string
{
    case Website = 'website';
    case Tool = 'tool';
    case Email = 'email';
    case Document = 'document';
    case Other = 'other';

    public function holdsEmail(): bool
    {
        return $this === self::Email;
    }

    /** English label, translated through `__()` where it is shown. */
    public function label(): string
    {
        return match ($this) {
            self::Website => 'Website',
            self::Tool => 'Tool',
            self::Email => 'Email address',
            self::Document => 'Document',
            self::Other => 'Other',
        };
    }
}
