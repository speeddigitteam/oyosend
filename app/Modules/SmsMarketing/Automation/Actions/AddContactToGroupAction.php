<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Modules\SmsMarketing\Actions\GroupManager;
use App\Modules\SmsMarketing\Automation\ActionReceiptStore;
use App\Modules\SmsMarketing\Models\Group;
use InvalidArgumentException;

/**
 * Adds an existing contact to an SMS group. It changes nothing about consent: a contact in a group is still
 * messaged only when the communication platform says it has marketing consent.
 */
final class AddContactToGroupAction implements ActionDefinition
{
    public function __construct(
        private readonly GroupManager $groups,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'sms_marketing.group.add_contact';
    }

    public static function label(): string
    {
        return 'automation.actions.sms_marketing.group.add_contact';
    }

    public static function module(): string
    {
        return 'sms_marketing';
    }

    public static function requiredPermission(): string
    {
        return 'sms_marketing.groups.manage';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['group_id', 'contact_id'],
            'properties' => [
                'group_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 40],
                'contact_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 40],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['group_id', 'contact_id', 'added'],
            'properties' => [
                'group_id' => ['type' => 'string'],
                'contact_id' => ['type' => 'string'],
                'added' => ['type' => 'boolean'],
            ],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input): array {
            $group = Group::query()->find((string) ($input['group_id'] ?? ''));

            if ($group === null) {
                throw new InvalidArgumentException('The group does not exist in this workspace.');
            }

            $contactId = (string) ($input['contact_id'] ?? '');
            $result = $this->groups->addContacts($group, [$contactId]);

            if ($result['missing'] > 0) {
                throw new InvalidArgumentException('The contact does not exist in this workspace.');
            }

            return ['group_id' => $group->id, 'contact_id' => $contactId, 'added' => $result['added'] > 0];
        });
    }
}
