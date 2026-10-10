<?php

declare(strict_types=1);

namespace App\Core\Contacts\Events;

use App\Core\Contacts\Models\Company;
use App\Core\Events\TenantDomainEvent;

final class CompanyDeleted extends TenantDomainEvent
{
    public readonly string $companyId;

    public readonly string $name;

    public readonly ?string $email;

    public readonly ?string $phone;

    public function __construct(Company $company)
    {
        parent::__construct();

        $this->companyId = $company->id;
        $this->name = $company->name;
        $this->email = $company->email;
        $this->phone = $company->phone;
    }

    public static function name(): string
    {
        return 'core.company.deleted';
    }

    public function payload(): array
    {
        return [
            'company_id' => $this->companyId,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
        ];
    }
}
