<?php

declare(strict_types=1);

namespace App\Modules\CRM\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\CRM\Actions\LeadManager;
use App\Modules\CRM\Automation\ActionReceiptStore;
use App\Modules\CRM\Enums\LeadStatus;
use App\Modules\CRM\Models\Lead;
use Illuminate\Validation\ValidationException;

/**
 * Create a lead for a shared contact: lead capture from another app's event (a first WhatsApp message, a form,
 * a support ticket). With `skip_if_open` (the default) a contact who already has an open lead (new, contacted or
 * qualified) gets no second one; the existing lead is returned with `created: false`. A retried step returns the
 * stored output of the first run.
 */
final class CreateLeadAction implements ActionDefinition
{
    private const array OPEN = [LeadStatus::New, LeadStatus::Contacted, LeadStatus::Qualified];

    public function __construct(
        private readonly LeadManager $leads,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'crm.lead.create';
    }

    public static function label(): string
    {
        return 'automation.actions.crm.lead.create';
    }

    public static function module(): string
    {
        return 'crm';
    }

    public static function requiredPermission(): string
    {
        return 'crm.leads.edit';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['contact_id', 'title'],
            'properties' => [
                'contact_id' => ['type' => 'string'],
                'title' => ['type' => 'string', 'maxLength' => 200],
                'source_id' => ['type' => ['string', 'null']],
                'owner_id' => ['type' => ['string', 'null']],
                'skip_if_open' => ['type' => 'boolean'],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['lead_id', 'created'],
            'properties' => ['lead_id' => ['type' => 'string'], 'created' => ['type' => 'boolean']],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input): array {
            $contactId = is_string($input['contact_id'] ?? null) ? $input['contact_id'] : '';
            $title = trim(is_string($input['title'] ?? null) ? $input['title'] : '');

            if ($contactId === '' || $title === '' || mb_strlen($title) > 200) {
                throw ValidationException::withMessages(['title' => __('A lead needs a contact and a title of up to 200 characters.')]);
            }

            if (($input['skip_if_open'] ?? true) !== false) {
                $open = Lead::query()
                    ->where('contact_id', $contactId)
                    ->whereIn('status', array_map(static fn (LeadStatus $status): string => $status->value, self::OPEN))
                    ->oldest()
                    ->first();

                if ($open !== null) {
                    return ['lead_id' => $open->id, 'created' => false];
                }
            }

            $owner = ($input['owner_id'] ?? null) === null ? null : TenantMembership::query()->where('status', MembershipStatus::Active)->findOrFail($input['owner_id']);
            $lead = $this->leads->create([
                'contact_id' => $contactId,
                'title' => $title,
                'source_id' => $input['source_id'] ?? null,
            ], $owner);

            return ['lead_id' => $lead->id, 'created' => true];
        });
    }
}
