<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Communication\Support\PhoneNumber;
use App\Core\Contacts\Models\Contact;
use App\Modules\Communication\Enums\Channel;

/**
 * Finds the shared contact behind a canonical channel address. A phone is matched in every spelling a contact
 * may have been saved in (`01712345678`, `+880 1712-345678`, `01712.345.678`, ...), because the address is the
 * canonical E.164: through the indexed `contacts.phone_e164`, with a scan only of rows that have none.
 */
final class ContactLocator
{
    public function idFor(Channel $channel, ?string $address): ?string
    {
        if ($address === null || $address === '') {
            return null;
        }

        if ($channel === Channel::Email) {
            $id = Contact::query()->where('email', $address)->value('id');

            return is_string($id) ? $id : null;
        }

        // Indexed equality on the canonical form the Contact model maintains.
        $id = Contact::query()->where('phone_e164', $address)->orderBy('id')->value('id');

        if (is_string($id)) {
            return $id;
        }

        $digits = PhoneNumber::digitVariants($address);

        if ($digits === []) {
            return null;
        }

        // Rows whose phone could not be read into a canonical form (or were written around the model):
        // the old spelling-tolerant match, restricted to those rows.
        $strip = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";
        $marks = implode(',', array_fill(0, count($digits), '?'));
        $id = Contact::query()->whereNotNull('phone')->whereNull('phone_e164')->whereRaw($strip.' in ('.$marks.')', $digits)->orderBy('id')->value('id');

        return is_string($id) ? $id : null;
    }

    public function find(Channel $channel, ?string $address): ?Contact
    {
        $id = $this->idFor($channel, $address);

        return $id === null ? null : Contact::query()->find($id);
    }
}
