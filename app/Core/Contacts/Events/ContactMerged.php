<?php

declare(strict_types=1);

namespace App\Core\Contacts\Events;

use App\Core\Contacts\Models\Contact;
use App\Core\Events\TenantDomainEvent;

final class ContactMerged extends TenantDomainEvent
{
    /**
     * @param  list<string>  $resolvedFields
     */
    public function __construct(
        Contact $canonical,
        public readonly string $duplicateId,
        public readonly string $duplicateName,
        public readonly array $resolvedFields,
    ) {
        parent::__construct();

        $this->canonicalId = $canonical->id;
        $this->canonicalName = $canonical->fullName();
    }

    public readonly string $canonicalId;

    public readonly string $canonicalName;

    public static function name(): string
    {
        return 'core.contact.merged';
    }

    public function payload(): array
    {
        return [
            'canonical_contact_id' => $this->canonicalId,
            'duplicate_contact_id' => $this->duplicateId,
            'canonical_name' => $this->canonicalName,
            'duplicate_name' => $this->duplicateName,
            'resolved_fields' => $this->resolvedFields,
        ];
    }
}
