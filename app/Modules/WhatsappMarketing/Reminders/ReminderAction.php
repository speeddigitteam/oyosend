<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Reminders;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Core\Contacts\Models\Contact;
use App\Modules\WhatsappMarketing\Actions\ConversationManager;
use App\Modules\WhatsappMarketing\Actions\Messenger;
use App\Modules\WhatsappMarketing\Actions\WhatsappAccess;
use App\Modules\WhatsappMarketing\Automation\ActionReceiptStore;
use App\Modules\WhatsappMarketing\Enums\SenderType;
use App\Modules\WhatsappMarketing\Models\Template;
use Illuminate\Validation\ValidationException;

/**
 * Shared behaviour of the transactional reminder and notification actions (order confirmation, invoice
 * notification, appointment reminder, payment reminder). Each sends one approved WhatsApp template to a contact,
 * with string parameters that the workflow maps from the triggering event's payload. The communication platform
 * decides consent, suppression, the 24-hour window and quiet hours: a template outside an open service window is
 * marketing and needs an opt-in, so a contact without one yields a stored `blocked` output, never an exception.
 *
 * Each subclass has its own key, so a retried step returns the first output per (action, idempotency key) and the
 * same send is never repeated. These actions take their values from the event or from manual input; they invent no
 * order, invoice or cart data.
 */
abstract class ReminderAction implements ActionDefinition
{
    public function __construct(
        private readonly Messenger $messenger,
        private readonly ConversationManager $conversations,
        private readonly ActionReceiptStore $receipts,
        private readonly WhatsappAccess $access,
    ) {}

    public static function module(): string
    {
        return 'whatsapp_marketing';
    }

    public static function requiredPermission(): ?string
    {
        return 'whatsapp_marketing.conversations.reply';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['contact_id', 'template_id'],
            'properties' => [
                'contact_id' => ['type' => 'string'],
                'template_id' => ['type' => 'string'],
                'parameters' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 20],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['state'],
            'properties' => [
                'state' => ['type' => 'string'],
                'reason' => ['type' => ['string', 'null']],
                'conversation_id' => ['type' => ['string', 'null']],
                'message_id' => ['type' => ['string', 'null']],
            ],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(static::key(), $idempotencyKey, function () use ($input): array {
            if (! $this->access->entitled()) {
                return $this->refused('module_unavailable');
            }

            $contact = Contact::query()->find((string) ($input['contact_id'] ?? ''));

            if ($contact === null) {
                return $this->refused('unknown_contact');
            }

            $template = Template::query()->find((string) ($input['template_id'] ?? ''));

            if ($template === null) {
                return $this->refused('unknown_template');
            }

            $conversation = $this->conversations->forContact($contact);

            if ($conversation === null) {
                return $this->refused('invalid_phone');
            }

            $parameters = array_values(array_map(
                static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                (array) ($input['parameters'] ?? []),
            ));

            try {
                $outcome = $this->messenger->sendTemplate($conversation, $template, $parameters, null, SenderType::Automation);
            } catch (ValidationException $exception) {
                return $this->refused((string) array_key_first($exception->errors()));
            }

            return [
                'state' => $outcome->receipt->state->value,
                'reason' => $outcome->receipt->reason,
                'conversation_id' => $conversation->id,
                'message_id' => $outcome->message->id,
            ];
        });
    }

    /** @return array<string, mixed> */
    private function refused(string $reason): array
    {
        return ['state' => 'refused', 'reason' => $reason, 'conversation_id' => null, 'message_id' => null];
    }
}
