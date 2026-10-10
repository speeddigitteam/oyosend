<?php

declare(strict_types=1);

namespace App\Core\Contacts;

use App\Core\Access\RecordAccess;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class ContactDuplicateDetector
{
    public function __construct(private readonly RecordAccess $records) {}

    /**
     * Exact, normalized identity matches that the actor is allowed to see.
     *
     * @return Collection<int, Contact>
     */
    public function find(
        User $actor,
        ?string $email,
        ?string $phone,
        ?Contact $exclude = null,
    ): Collection {
        $email = $this->email($email);
        $phone = $this->phone($phone);

        if ($email === null && $phone === null) {
            return new Collection;
        }

        $query = $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)
            ->with(['company:id,name', 'owner.user:id,name'])
            ->when($exclude !== null, fn ($builder) => $builder->whereKeyNot($exclude->getKey()))
            ->where(function ($match) use ($email, $phone): void {
                if ($email !== null) {
                    $match->whereRaw('LOWER(email) = ?', [$email]);
                }

                if ($phone !== null) {
                    $method = $email === null ? 'whereRaw' : 'orWhereRaw';
                    $match->{$method}($this->normalizedPhoneSql().' = ?', [$phone]);
                }
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id');

        return $query->get();
    }

    public function normalizedEmail(?string $email): ?string
    {
        return $this->email($email);
    }

    public function normalizedPhone(?string $phone): ?string
    {
        return $this->phone($phone);
    }

    private function email(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    private function phone(?string $phone): ?string
    {
        $phone = preg_replace('/[^0-9]/', '', (string) $phone);

        return $phone === '' ? null : $phone;
    }

    private function normalizedPhoneSql(): string
    {
        return "REGEXP_REPLACE(phone, '[^0-9]', '')";
    }
}
