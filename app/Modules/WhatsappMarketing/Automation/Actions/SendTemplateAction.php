<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Automation\Actions;

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
 * Sends an approved template to a contact. It is a marketing message unless the template is a utility template
 * for someone who already wrote to us: either way the communication platform decides consent and suppression, so
 * a contact without an opt-in yields a stored `blocked` output, not an exception. A retry with the same
 * idempotency key returns the first output and sends nothing.
 */
final class SendTemplateAction implements ActionDefinition
{
    public function __construct(
        private readonly Messenger $messenger,
        private readonly ConversationManager $conversations,
        private readonly ActionReceiptStore $receipts,
        private readonly WhatsappAccess $access,
    ) {}

    public static function key(): string
    {
        return 'whatsapp_marketing.template.send';
    }

    public static function label(): string
    {
        return 'automation.actions.whatsapp_marketing.template.send';
    }

    public static function module(): string
    {
        return 'whatsapp_marketing';
    }

    public static function requiredPermission(): string
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
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input): array {
            if (! $this->access->entitled()) {
                return $this->refused('module_unavailable');
            }

            $contact = Contact::query()->find((string) ($input['contact_id'] ?? ''));
            $template = Template::query()->find((string) ($input['template_id'] ?? ''));

            if ($contact === null) {
                return $this->refused('unknown_contact');
            }

            if ($template === null) {
                return $this->refused('unknown_template');
            }

            $conversation = $this->conversations->forContact($contact);

            if ($conversation === null) {
                return $this->refused('invalid_phone');
            }

            $parameters = array_values(array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', (array) ($input['parameters'] ?? [])));

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
