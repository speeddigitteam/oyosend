<?php

declare(strict_types=1);

namespace App\Core\Contacts\Enums;

/**
 * What an address of a company or a contact is for, as Odoo's "Contacts & Addresses" tells them apart. Each parent has
 * at most one default address of each type.
 */
enum AddressType: string
{
    case Invoice = 'invoice';
    case Delivery = 'delivery';
    case Other = 'other';

    /** The order a parent's addresses are listed in. */
    public function rank(): int
    {
        return match ($this) {
            self::Invoice => 0,
            self::Delivery => 1,
            self::Other => 2,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
