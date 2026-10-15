<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Contracts\InboxSignals;
use App\Core\Communication\Data\DeliveryState;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\MarketingMessage;
use App\Core\Communication\Data\MessagingPurpose;
use App\Core\Communication\Data\Whatsapp\MediaReference;
use App\Core\Communication\Data\Whatsapp\WhatsappContent;
use App\Core\Communication\Data\Whatsapp\WhatsappMediaType;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use App\Modules\WhatsappMarketing\Enums\Direction;
use App\Modules\WhatsappMarketing\Enums\MessageStatus;
use App\Modules\WhatsappMarketing\Enums\MessageType;
use App\Modules\WhatsappMarketing\Enums\SenderType;
use App\Modules\WhatsappMarketing\Inbox\ConversationInboxSource;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Message;
use App\Modules\WhatsappMarketing\Support\Interactive;
use App\Modules\WhatsappMarketing\Support\Phone;
use App\Modules\WhatsappMarketing\Support\Text;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rich interactive and media WhatsApp messages (ADR 0022), built as {@see WhatsappContent} and sent through the
 * communication platform's {@see ChannelGateway}. Everything here is free-form content, so it is allowed only
 * inside the customer's open 24-hour service window: the window rule is checked here, named to the gateway with
 * `whatsapp_window_until`, and enforced again by the gateway and the provider. The plain template path (with its
 * own consent rules) stays in {@see Messenger}.
 *
 * The agent chooses the sending business number in the composer; its id is passed as
 * `MarketingMessage::$senderNumberId` (null means the workspace default).
 */
final class RichComposer
{
    public function __construct(
        private readonly ChannelGateway $gateway,
        private readonly WindowTracker $windows,
        private readonly VariableResolver $variables,
        private readonly SettingsManager $settings,
        private readonly TenantContext $context,
        private readonly InboxSignals $signals,
    ) {}

    /**
     * Up to three reply buttons or a list of up to ten rows, inside the open window.
     *
     * @throws ValidationException
     */
    public function sendInteractive(Conversation $conversation, Interactive $interactive, string $body, ?TenantMembership $author, ?string $senderNumberId = null): Message
    {
        $until = $this->requireWindow($conversation);
        [$contact, $body] = $this->prepare($conversation, $body, $author, Interactive::MAX_BODY, 'body');

        $content = $interactive->kind === Interactive::BUTTONS
            ? WhatsappContent::buttons($this->buttonSpec($interactive->options))
            : WhatsappContent::list((string) $interactive->button, [['rows' => $this->rowSpec($interactive->options)]]);

        return $this->dispatch(
            $conversation,
            $contact,
            MessageType::Interactive,
            $body,
            $author,
            $content,
            $until,
            $senderNumberId,
            interactive: $interactive->toArray(),
        );
    }

    /**
     * A call-to-action URL button, inside the open window.
     *
     * @throws ValidationException
     */
    public function sendCtaUrl(Conversation $conversation, string $body, string $label, string $url, ?TenantMembership $author, ?string $senderNumberId = null): Message
    {
        $until = $this->requireWindow($conversation);
        [$contact, $body] = $this->prepare($conversation, $body, $author, WhatsappContent::MAX_BODY, 'body');

        try {
            $content = WhatsappContent::ctaUrl($label, $url);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['url' => __('Enter a button label (up to 20 characters) and a valid web address.')]);
        }

        return $this->dispatch($conversation, $contact, MessageType::Interactive, $body, $author, $content, $until, $senderNumberId);
    }

    /**
     * An image, document, video or audio message from the workspace media store, inside the open window.
     *
     * @throws ValidationException
     */
    public function sendMedia(Conversation $conversation, string $mediaId, WhatsappMediaType $type, ?string $caption, ?string $filename, ?TenantMembership $author, ?string $senderNumberId = null): Message
    {
        $until = $this->requireWindow($conversation);
        $caption = $caption === null ? null : (Text::clean($caption, WhatsappContent::MAX_BODY) ?: null);

        if ($type === WhatsappMediaType::Audio) {
            $caption = null;
        }

        $contact = $this->contactFor($conversation);

        try {
            $content = WhatsappContent::media(new MediaReference($type, mediaId: $mediaId, filename: $filename), $caption);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['file' => __('That file cannot be sent.')]);
        }

        return $this->dispatch(
            $conversation,
            $contact,
            $this->mediaType($type),
            $caption ?? '',
            $author,
            $content,
            $until,
            $senderNumberId,
            attachments: [['kind' => $type->value, 'mime' => null, 'has_caption' => $caption !== null]],
        );
    }

    /**
     * Record the message, hand it to the gateway as a transactional reply, and store the outcome. The gateway
     * records nothing when the window is closed or the sending number is gone: the row is then removed.
     *
     * @param  array{kind: string, button: string|null, options: list<string>}|null  $interactive
     * @param  list<array<string, scalar|null>>|null  $attachments
     *
     * @throws ValidationException
     */
    private function dispatch(
        Conversation $conversation,
        Contact $contact,
        MessageType $type,
        string $body,
        ?TenantMembership $author,
        WhatsappContent $content,
        CarbonImmutable $windowUntil,
        ?string $senderNumberId,
        ?array $interactive = null,
        ?array $attachments = null,
    ): Message {
        $now = CarbonImmutable::now();

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => Direction::Outbound,
            'sender_type' => SenderType::Agent,
            'author_id' => $author?->id,
            'type' => $type,
            'body' => $body === '' ? null : $body,
            'interactive' => $interactive,
            'attachments' => $attachments,
            'status' => MessageStatus::Queued,
            'occurred_at' => $now,
        ]);

        $receipt = $this->gateway->send(new MarketingMessage(
            module: Messenger::MODULE,
            idempotencyKey: 'msg:'.$message->id,
            channel: MarketingChannel::Whatsapp,
            contactId: $contact->id,
            subject: null,
            body: $body === '' ? '.' : $body,
            purpose: MessagingPurpose::Transactional,
            providerHints: ['whatsapp_window_until' => $windowUntil->toIso8601String()],
            senderNumberId: $senderNumberId,
            whatsapp: $content,
        ));

        if ($receipt->state === DeliveryState::Unavailable) {
            $message->delete();

            throw ValidationException::withMessages(['body' => $this->unavailableMessage((string) $receipt->reason)]);
        }

        $status = match ($receipt->state) {
            DeliveryState::Blocked => MessageStatus::Blocked,
            DeliveryState::Failed, DeliveryState::Bounced, DeliveryState::Cancelled => MessageStatus::Failed,
            DeliveryState::Sent => MessageStatus::Sent,
            DeliveryState::Delivered => MessageStatus::Delivered,
            default => MessageStatus::Queued,
        };

        $message->forceFill([
            'communication_message_id' => $receipt->messageId,
            'status' => $status,
            'failure_code' => in_array($status, [MessageStatus::Blocked, MessageStatus::Failed], true) ? $receipt->reason : null,
        ])->save();

        $this->touch($conversation, $message, $author);

        return $message;
    }

    private function touch(Conversation $conversation, Message $message, ?TenantMembership $author): void
    {
        DB::transaction(function () use ($conversation, $message, $author): void {
            $locked = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $locked->last_message_at = $message->occurred_at;
            $locked->last_message_preview = Text::preview((string) ($message->body ?? __('Attachment')));
            $locked->last_message_direction = 'outbound';
            $locked->first_responded_at ??= $message->occurred_at;
            $locked->bot_paused = true;
            $locked->bot_state = null;
            $locked->bot_state_expires_at = null;

            if ($locked->owner_id === null && $author !== null) {
                $locked->assignTo($author);
            }

            $locked->unread_count = 0;
            $locked->save();
            $conversation->setRawAttributes($locked->getAttributes(), true);
            $this->signals->changed(ConversationInboxSource::KEY, $locked->id);
        });
    }

    /**
     * The service window must be open for any free-form message.
     *
     * @throws ValidationException
     */
    private function requireWindow(Conversation $conversation): CarbonImmutable
    {
        return $this->windows->serviceWindowUntil($conversation)
            ?? throw ValidationException::withMessages(['body' => __('The 24-hour window has closed. Send an approved template instead.')]);
    }

    /**
     * Resolve the contact, render placeholders in the body and cap it.
     *
     * @return array{0: Contact, 1: string}
     *
     * @throws ValidationException
     */
    private function prepare(Conversation $conversation, string $body, ?TenantMembership $author, int $max, string $field): array
    {
        $contact = $this->contactFor($conversation);
        $company = $contact->company_id === null ? null : $contact->company()->first();
        $values = $this->variables->valuesFor($contact, $company, $this->workspaceName(), $author?->loadMissing('user:id,name')->user?->name);
        $body = Text::clean($this->variables->render($body, $values), Messenger::MAX_TEXT);

        if ($body === '') {
            throw ValidationException::withMessages([$field => __('Write a message first.')]);
        }

        if (mb_strlen($body) > $max) {
            throw ValidationException::withMessages([$field => __('A message with buttons or a list has up to :max characters.', ['max' => $max])]);
        }

        return [$contact, $body];
    }

    /** @throws ValidationException */
    private function contactFor(Conversation $conversation): Contact
    {
        $contact = $conversation->contact_id === null ? null : Contact::query()->find($conversation->contact_id);

        if ($contact === null) {
            throw ValidationException::withMessages(['body' => __('Add this number to your contacts before sending.')]);
        }

        if (Phone::e164($contact->phone) !== $conversation->phone) {
            throw ValidationException::withMessages(['body' => __('The contact\'s phone number does not match this conversation.')]);
        }

        return $contact;
    }

    /**
     * @param  list<string>  $labels
     * @return list<array{id: string, title: string}>
     */
    private function buttonSpec(array $labels): array
    {
        $spec = [];

        foreach ($labels as $index => $label) {
            $spec[] = ['id' => 'btn-'.($index + 1), 'title' => $label];
        }

        return $spec;
    }

    /**
     * @param  list<string>  $labels
     * @return list<array{id: string, title: string}>
     */
    private function rowSpec(array $labels): array
    {
        $spec = [];

        foreach ($labels as $index => $label) {
            $spec[] = ['id' => 'row-'.($index + 1), 'title' => $label];
        }

        return $spec;
    }

    private function mediaType(WhatsappMediaType $type): MessageType
    {
        return match ($type) {
            WhatsappMediaType::Image => MessageType::Image,
            WhatsappMediaType::Document => MessageType::Document,
            WhatsappMediaType::Audio => MessageType::Audio,
            WhatsappMediaType::Video => MessageType::Video,
        };
    }

    private function unavailableMessage(string $reason): string
    {
        return match ($reason) {
            'module_unavailable', 'channel_disabled', 'provider_not_configured' => __('WhatsApp is not connected for this workspace yet.'),
            'outside_customer_service_window' => __('The 24-hour window has closed. Send an approved template instead.'),
            'sender_number_unavailable' => __('That WhatsApp number is not available to send from.'),
            'no_address', 'unknown_contact' => __('This contact has no valid WhatsApp number.'),
            default => __('The message could not be queued.'),
        };
    }

    private function workspaceName(): string
    {
        return $this->settings->current()->business_name ?? $this->context->require()->name;
    }
}
