<?php

declare(strict_types=1);

namespace App\Core\Contacts\Events;

use App\Core\Contacts\Models\Contact;
use App\Core\Events\TenantDomainEvent;

final class ContactDeleted extends TenantDomainEvent
{
    public readonly string $contactId;

    public readonly ?string $companyId;

    public readonly string $name;

    public readonly ?string $email;

    public readonly ?string $phone;

    public function __construct(Contact $contact)
    {
        parent::__construct();

        $this->contactId = $contact->id;
        $this->companyId = $contact->company_id;
        $this->name = $contact->fullName();
        $this->email = $contact->email;
        $this->phone = $contact->phone;
    }

    public static function name(): string
    {
        return 'core.contact.deleted';
    }

    public function payload(): array
    {
        return [
            'contact_id' => $this->contactId,
            'company_id' => $this->companyId,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
        ];
    }
}
