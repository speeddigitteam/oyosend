<?php

declare(strict_types=1);

namespace App\Modules\Communication\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Core\Contacts\Models\Contact;
use App\Modules\Communication\Actions\ChannelConnections;
use App\Modules\Communication\Actions\CommunicationAccess;
use App\Modules\Communication\Actions\OutboundRequest;
use App\Modules\Communication\Actions\Outbox;
use App\Modules\Communication\Actions\TemplateManager;
use App\Modules\Communication\Automation\ActionReceiptStore;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConfigurationState;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Enums\MessageStatus;

/**
 * Sends a TRANSACTIONAL template to a contact. Marketing templates are refused: a workflow can never send
 * promotional messages, because consent, quiet hours and the unsubscribe link are a person's responsibility.
 *
 * It never throws for a business refusal; the refusal is the stored, replayable output.
 */
final class SendTemplateMessageAction implements ActionDefinition
{
    public function __construct(
        private readonly TemplateManager $templates,
        private readonly Outbox $outbox,
        private readonly ChannelConnections $connections,
        private readonly CommunicationAccess $access,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'communication.message.send';
    }

    public static function label(): string
    {
        return 'automation.actions.communication.message.send';
    }

    public static function module(): string
    {
        return 'communication';
    }

    public static function requiredPermission(): string
    {
        return 'communication.messages.send';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['template', 'contact_id'],
            'properties' => [
                'template' => ['type' => 'string', 'maxLength' => 80],
                'channel' => ['type' => 'string', 'enum' => Channel::values(), 'default' => 'email'],
                'contact_id' => ['type' => 'string'],
                'variables' => [
                    'type' => 'object',
                    'description' => 'Values for placeholders other modules registered. Unknown keys are ignored.',
                    'additionalProperties' => ['type' => ['string', 'number', 'null']],
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['status'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['queued', 'rejected']],
                'message_id' => ['type' => ['string', 'null']],
                'reason' => ['type' => ['string', 'null']],
            ],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input, $idempotencyKey): array {
            if (! $this->access->entitled()) {
                return $this->rejected('module_not_entitled');
            }

            $channel = Channel::tryFrom((string) ($input['channel'] ?? 'email'));
            $contact = Contact::query()->with('company:id,name')->find((string) ($input['contact_id'] ?? ''));

            if ($channel === null || $contact === null) {
                return $this->rejected($channel === null ? 'invalid_channel' : 'contact_not_found');
            }

            $template = $this->templates->resolve((string) ($input['template'] ?? ''), $channel);

            if ($template === null) {
                return $this->rejected('template_not_found');
            }

            if ($template->purpose !== MessagePurpose::Transactional) {
                return $this->rejected('template_not_transactional');
            }

            $address = $channel->normalize((string) ($channel === Channel::Email ? $contact->email : $contact->phone));

            if ($address === null) {
                return $this->rejected('contact_has_no_address');
            }

            if ($this->connections->state($channel) === ConfigurationState::NotConfigured) {
                return $this->rejected('channel_not_configured');
            }

            $variables = array_filter((array) ($input['variables'] ?? []), 'is_scalar');
            $rendered = $this->templates->render($template, $this->templates->values($contact, $contact->company, $variables));

            $message = $this->outbox->queue(new OutboundRequest(
                channel: $channel,
                purpose: MessagePurpose::Transactional,
                recipient: $address,
                subject: $rendered['subject'],
                body: $rendered['body'],
                idempotencyKey: 'automation:'.$idempotencyKey,
                contactId: $contact->id,
                companyId: $contact->company_id,
                templateId: $template->id,
                locale: $template->locale,
                source: 'automation',
            ));

            // A suppressed or limit-failed message is still a row in the log; say what became of it.
            return [
                'status' => in_array($message->status, [MessageStatus::Suppressed, MessageStatus::Failed, MessageStatus::Cancelled, MessageStatus::Bounced], true) ? 'rejected' : 'queued',
                'message_id' => $message->id,
                'reason' => $message->failure_code,
            ];
        });
    }

    /** @return array{status: string, message_id: null, reason: string} */
    private function rejected(string $reason): array
    {
        return ['status' => 'rejected', 'message_id' => null, 'reason' => $reason];
    }
}
