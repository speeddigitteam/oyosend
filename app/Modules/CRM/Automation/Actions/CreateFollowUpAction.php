<?php

declare(strict_types=1);

namespace App\Modules\CRM\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\CRM\Actions\ActivityManager;
use App\Modules\CRM\Enums\ActivityPriority;
use App\Modules\CRM\Enums\ActivityType;
use App\Modules\CRM\Models\Activity;

final class CreateFollowUpAction implements ActionDefinition
{
    public function __construct(private readonly ActivityManager $activities) {}

    public static function key(): string
    {
        return 'crm.activity.create_follow_up';
    }

    public static function label(): string
    {
        return 'automation.actions.crm.activity.create_follow_up';
    }

    public static function module(): string
    {
        return 'crm';
    }

    public static function requiredPermission(): string
    {
        return 'crm.activities.edit';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['subject', 'due_at'], 'properties' => ['subject' => ['type' => 'string', 'maxLength' => 200], 'due_at' => ['type' => 'string', 'format' => 'date-time'], 'lead_id' => ['type' => ['string', 'null']], 'deal_id' => ['type' => ['string', 'null']], 'contact_id' => ['type' => ['string', 'null']], 'company_id' => ['type' => ['string', 'null']], 'owner_id' => ['type' => ['string', 'null']]], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['activity_id'], 'properties' => ['activity_id' => ['type' => 'string']]];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        $existing = Activity::query()->where('automation_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return ['activity_id' => $existing->id];
        }

        $owner = ($input['owner_id'] ?? null) === null ? null : TenantMembership::query()->where('status', MembershipStatus::Active)->findOrFail($input['owner_id']);
        $activity = $this->activities->create([
            'type' => ActivityType::FollowUp,
            'priority' => ActivityPriority::Normal,
            'subject' => $input['subject'],
            'due_at' => $input['due_at'],
            'lead_id' => $input['lead_id'] ?? null,
            'deal_id' => $input['deal_id'] ?? null,
            'contact_id' => $input['contact_id'] ?? null,
            'company_id' => $input['company_id'] ?? null,
            'automation_key' => $idempotencyKey,
        ], $owner);

        return ['activity_id' => $activity->id];
    }
}
