<?php

declare(strict_types=1);

namespace App\Core\Access\Enums;

/**
 * How far a granted permission reaches.
 *
 * Stored per role grant, for permissions that declare themselves scopable.
 * RecordAccess uses it to narrow record-level access, for example "may edit
 * contacts, but only their own".
 */
enum AccessScope: string
{
    case Own = 'own';
    case Department = 'department';
    case Branch = 'branch';
    case All = 'all';

    public function rank(): int
    {
        return match ($this) {
            self::Own => 1,
            self::Department => 2,
            self::Branch => 3,
            self::All => 4,
        };
    }

    /**
     * The wider of two scopes - used when several roles grant the same permission.
     */
    public function widest(self $other): self
    {
        return $other->rank() > $this->rank() ? $other : $this;
    }

    public function covers(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }
}
