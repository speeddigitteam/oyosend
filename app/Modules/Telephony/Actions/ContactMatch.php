<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Actions;

use App\Core\Contacts\Models\Contact;

/** The shared contact behind a canonical (E.164) number, through the indexed `contacts.phone_e164`. */
final class ContactMatch
{
    public function idFor(string $e164): ?string
    {
        $id = Contact::query()->where('phone_e164', $e164)->orderBy('id')->value('id');

        return is_string($id) ? $id : null;
    }
}
