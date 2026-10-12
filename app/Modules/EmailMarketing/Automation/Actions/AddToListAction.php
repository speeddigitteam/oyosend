<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Core\Contacts\Models\Contact;
use App\Modules\EmailMarketing\Actions\SubscriberManager;
use App\Modules\EmailMarketing\Automation\ActionReceiptStore;
use App\Modules\EmailMarketing\Enums\SubscriptionSource;
use App\Modules\EmailMarketing\Models\MailingList;
use App\Modules\EmailMarketing\Models\Subscription;
use Illuminate\Validation\ValidationException;

/**
 * Adds a contact to a list as `pending`. An automation can never record consent: the member stays unsendable
 * until a person gives consent evidence. The action is idempotent per workflow step (a stored receipt answers a
 * retry), and refusals (unknown list, unknown contact, a closed membership) are stored outputs, not exceptions.
 */
final class AddToListAction implements ActionDefinition
{
    public function __construct(
        private readonly SubscriberManager $subscribers,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'email_marketing.subscriber.add_to_list';
    }

    public static function label(): string
    {
        return 'automation.actions.email_marketing.subscriber.add_to_list';
    }

    public static function module(): string
    {
        return 'email_marketing';
    }

    public static function requiredPermission(): string
    {
        return 'email_marketing.subscribers.manage';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['list_id', 'contact_id'],
            'properties' => [
                'list_id' => ['type' => 'string'],
                'contact_id' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['added'],
            'properties' => [
                'added' => ['type' => 'boolean'],
                'subscription_id' => ['type' => ['string', 'null']],
                'status' => ['type' => ['string', 'null']],
                'reason' => ['type' => ['string', 'null']],
            ],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input): array {
            $list = MailingList::query()->find((string) ($input['list_id'] ?? ''));

            if ($list === null) {
                return $this->refused('list_not_found');
            }

            $contact = Contact::query()->find((string) ($input['contact_id'] ?? ''));

            if ($contact === null) {
                return $this->refused('contact_not_found');
            }

            try {
                /** @var Subscription $subscription */
                $subscription = $this->subscribers->add($list, $contact->id, [], SubscriptionSource::Automation);
            } catch (ValidationException $exception) {
                return $this->refused((string) collect($exception->errors())->flatten()->first());
            }

            return ['added' => true, 'subscription_id' => $subscription->id, 'status' => $subscription->status->value, 'reason' => null];
        });
    }

    /** @return array<string, mixed> */
    private function refused(string $reason): array
    {
        return ['added' => false, 'subscription_id' => null, 'status' => null, 'reason' => $reason];
    }
}
