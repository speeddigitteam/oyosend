<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools;

use App\Core\Access\RecordAccess;
use App\Core\Contacts\Models\Company;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Validation of contact fields for the proposing tools. It mirrors the contact form rules, and is run
 * twice: when the change is proposed and again when a person confirms it.
 */
final class ContactInput
{
    /** Fields the assistant may propose. Owner reassignment and deletion are deliberately absent. */
    public const array FIELDS = ['first_name', 'last_name', 'email', 'phone', 'job_title', 'city', 'country', 'notes', 'company_id'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly RecordAccess $records,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> The validated, normalised attributes (only keys that were given).
     */
    public function validate(array $input, User $actor, ?string $ignoreContactId = null, bool $creating = false): array
    {
        $input = array_intersect_key($input, array_flip(self::FIELDS));
        $presence = $creating ? 'required' : 'sometimes';

        $rules = [
            'first_name' => [$presence, 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => [
                'sometimes', 'nullable', 'string', 'email:rfc', 'max:255',
                Rule::unique('contacts', 'email')
                    ->where('tenant_id', $this->context->requireId())
                    ->whereNull('deleted_at')
                    ->ignore($ignoreContactId),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'company_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ];

        /** @var array<string, mixed> $valid */
        $valid = Validator::make($input, $rules)->validate();

        if (isset($valid['email'])) {
            $valid['email'] = mb_strtolower((string) $valid['email']);
        }

        if (isset($valid['company_id'])) {
            // Only a company the member can see: a scoped lookup, 404 otherwise.
            $valid['company_id'] = $this->records->constrain(Company::query(), $actor, 'core.contacts.view')
                ->findOrFail((string) $valid['company_id'])
                ->getKey();
        }

        return $valid;
    }
}
