<?php

declare(strict_types=1);

namespace App\Core\Contacts\Records;

use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\User;

/** The member asking and the contact asked about. The contact is already authorised for the member. */
final readonly class CustomerQuery
{
    public const int DEFAULT_LIMIT = 5;

    public const int MAX_LIMIT = 20;

    public function __construct(
        public User $user,
        public Contact $contact,
        public int $limit = self::DEFAULT_LIMIT,
    ) {}
}
