<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Data\DeliveryState;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\MarketingMessage;
use App\Core\Communication\Data\MessagingPurpose;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use App\Modules\WhatsappMarketing\Enums\Direction;
use App\Modules\WhatsappMarketing\Enums\MessageStatus;
use App\Modules\WhatsappMarketing\Enums\MessageType;
use App\Modules\WhatsappMarketing\Enums\SenderType;
use App\Modules\WhatsappMarketing\Enums\TemplateCategory;
use App\Modules\WhatsappMarketing\Enums\TemplateStatus;
use App\Modules\WhatsappMarketing\Models\Campaign;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Message;
use App\Modules\WhatsappMarketing\Models\Recipient;
use App\Modules\WhatsappMarketing\Models\Template;
use App\Modules\WhatsappMarketing\Support\Phone;
use App\Modules\WhatsappMarketing\Support\Text;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every outbound WhatsApp message of this app goes through here, and from here only to the communication
 * platform's {@see ChannelGateway}: consent, suppression, quiet hours, rate limits, retries and the provider
 * are its business. This class adds the WhatsApp rules the platform cannot know:
 *
 *  - a free-form reply needs a running 24-hour customer-service window (enforced here, again by the adapter at
 *    send time through `whatsapp_window_until`, and by Meta);
 *  - outside the window only an APPROVED template is sent;
 *  - a campaign message is ALWAYS marketing: a tenant-chosen "utility" label never exempts a bulk send from
 *    consent, suppression or quiet hours. A one-to-one template an agent sends is transactional only for a
 *    non-marketing template while the customer's own 24-hour window is open (the customer just wrote to us);
 *    otherwise it needs a recorded opt-in like any marketing message.
 */
final class Messenger
{
    public const string MODULE = 'whatsapp_marketing';

    public const int MAX_TEXT = 4096;

    public function __construct(
        private readonly ChannelGateway $gateway,
        private readonly WindowTracker $windows,
        private readonly VariableResolver $variables,
        private readonly TenantContext $context,
        private readonly SettingsManager $settings,
    ) {}

    /**
     * A free-form reply to a customer who wrote within the last 24 hours.
     *
     * @throws ValidationException
     */
    public function reply(Conversation $conversation, string $text, ?TenantMembership $author, SenderType $by = SenderType::Agent): Message
    {
        $until = $this->windows->serviceWindowUntil($conversation);

        if ($until === null) {
            throw ValidationException::withMessages(['body' => __('The 24-hour window has closed. Send an approved template instead.')]);
        }

        $contact = $this->contactFor($conversation, 'body');
        $company = $contact->company_id === null ? null : $contact->company()->first();
        $values = $this->variables->valuesFor($contact, $company, $this->workspaceName(), $author?->loadMissing('user:id,name')->user?->name);
        $body = Text::clean($this->variables->render($text, $values), self::MAX_TEXT);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => __('Write a message first.')]);
        }

        $outcome = $this->dispatch(
            $conversation,
            $contact,
            type: MessageType::Text,
            body: $body,
            sender: $by,
            author: $author,
            purpose: MessagingPurpose::Transactional,
            hints: ['whatsapp_window_until' => $until->toIso8601String()],
            template: null,
            campaign: null,
            recipient: null,
            field: 'body',
        );

        return $outcome->message;
    }

    /**
     * An approved template, inside or outside the window.
     *
     * @param  list<string>  $parameters  Values for `{{1}}`, `{{2}}`, ...
     *
     * @throws ValidationException
     */
    public function sendTemplate(
        Conversation $conversation,
        Template $template,
        array $parameters,
        ?TenantMembership $author,
        SenderType $by = SenderType::Agent,
        ?Campaign $campaign = null,
        ?Recipient $recipient = null,
    ): SendOutcome {
        if ($template->status !== TemplateStatus::Approved) {
            throw ValidationException::withMessages(['template_id' => __('Only approved templates can be sent.')]);
        }

        if (count($parameters) !== $template->variable_count) {
            throw ValidationException::withMessages(['variables' => __('This template needs :count variables.', ['count' => $template->variable_count])]);
        }

        if (! $this->windows->canStartBusinessWindow($conversation)) {
            throw ValidationException::withMessages(['template_id' => __('Your plan\'s limit has been reached. Upgrade the plan to add more.')]);
        }

        $contact = $this->contactFor($conversation, 'template_id');
        $parameters = array_map(static fn (string $value): string => Text::clean($value, 1024, false), $parameters);
        $body = Text::clean(TemplateParameters::render($template->body_text, $parameters), self::MAX_TEXT);

        // Campaigns, marketing templates and anything sent outside a running service window need an opt-in.
        $until = $this->windows->serviceWindowUntil($conversation);
        $bulk = $campaign !== null || $recipient !== null || $by === SenderType::Campaign;
        $purpose = $bulk
            || $template->category === TemplateCategory::Marketing
            || $until === null
            ? MessagingPurpose::Marketing
            : MessagingPurpose::Transactional;

        return $this->dispatch(
            $conversation,
            $contact,
            type: MessageType::Template,
            body: $body,
            sender: $by,
            author: $author,
            purpose: $purpose,
            hints: [
                'whatsapp_template' => $template->name,
                'whatsapp_language' => $template->language,
                'whatsapp_params' => (string) json_encode($parameters, JSON_UNESCAPED_UNICODE),
                // The platform honours "transactional" only against an open window: it is named here.
                ...($purpose === MessagingPurpose::Transactional ? ['whatsapp_window_until' => $until->toIso8601String()] : []),
            ],
            template: $template,
            campaign: $campaign,
            recipient: $recipient,
            field: 'template_id',
        );
    }

    /**
     * @param  array<string, scalar|null>  $hints
     */
    private function dispatch(
        Conversation $conversation,
        Contact $contact,
        MessageType $type,
        string $body,
        SenderType $sender,
        ?TenantMembership $author,
        MessagingPurpose $purpose,
        array $hints,
        ?Template $template,
        ?Campaign $campaign,
        ?Recipient $recipient,
        string $field,
    ): SendOutcome {
        $now = CarbonImmutable::now();

        $message = $campaign !== null && $recipient !== null && $recipient->message_id !== null
            ? Message::query()->find($recipient->message_id)
            : null;

        $message ??= Message::query()->create([
            'conversation_id' => $conversation->id,
            'campaign_id' => $campaign?->id,
            'template_id' => $template?->id,
            'direction' => Direction::Outbound,
            'sender_type' => $sender,
            'author_id' => $author?->id,
            'type' => $type,
            'body' => $body,
            'template_name' => $template?->name,
            'status' => MessageStatus::Queued,
            'occurred_at' => $now,
        ]);

        if ($recipient !== null && $recipient->message_id !== $message->id) {
            // Linked before anything is sent: a status event may arrive before this call returns.
            $recipient->forceFill(['message_id' => $message->id])->save();
        }

        $key = $campaign !== null && $recipient !== null
            ? 'campaign:'.$campaign->id.':recipient:'.$recipient->id
            : 'msg:'.$message->id;

        $receipt = $this->gateway->send(new MarketingMessage(
            module: self::MODULE,
            idempotencyKey: $key,
            channel: MarketingChannel::Whatsapp,
            contactId: $contact->id,
            subject: null,
            body: $body,
            campaignId: $campaign?->id,
            recipientId: $campaign !== null && $recipient !== null ? $recipient->id : $message->id,
            locale: null,
            sendAt: null,
            purpose: $purpose,
            providerHints: $hints,
        ));

        if ($receipt->state === DeliveryState::Unavailable) {
            // Nothing was recorded by the platform: nothing was sent, and the row would only mislead.
            if ($campaign === null) {
                $message->delete();
                throw ValidationException::withMessages([$field => $this->unavailableMessage((string) $receipt->reason)]);
            }

            $message->forceFill(['status' => MessageStatus::Failed, 'failure_code' => $receipt->reason])->save();

            return new SendOutcome($message, $receipt);
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

        if ($campaign === null) {
            $this->touch($conversation, $message, $author, $sender);
        }

        return new SendOutcome($message, $receipt);
    }

    private function touch(Conversation $conversation, Message $message, ?TenantMembership $author, SenderType $sender): void
    {
        DB::transaction(function () use ($conversation, $message, $author, $sender): void {
            $locked = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $locked->last_message_at = $message->occurred_at;
            $locked->last_message_preview = Text::preview((string) $message->body);
            $locked->last_message_direction = 'outbound';

            if ($sender === SenderType::Agent) {
                $locked->first_responded_at ??= $message->occurred_at;
                // A person answered: the bot steps back, and an unassigned conversation becomes theirs.
                $locked->bot_paused = true;
                $locked->bot_state = null;
                $locked->bot_state_expires_at = null;

                if ($locked->owner_id === null && $author !== null) {
                    $locked->assignTo($author);
                }

                $locked->unread_count = 0;
            }

            $locked->save();
            $conversation->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** The shared contact behind the conversation, whose phone must be the conversation's number. */
    private function contactFor(Conversation $conversation, string $field): Contact
    {
        $contact = $conversation->contact_id === null ? null : Contact::query()->find($conversation->contact_id);

        if ($contact === null) {
            throw ValidationException::withMessages([$field => __('Add this number to your contacts before sending.')]);
        }

        if (Phone::e164($contact->phone) !== $conversation->phone) {
            throw ValidationException::withMessages([$field => __('The contact\'s phone number does not match this conversation.')]);
        }

        return $contact;
    }

    private function unavailableMessage(string $reason): string
    {
        return match ($reason) {
            'module_unavailable', 'channel_disabled', 'provider_not_configured' => __('WhatsApp is not connected for this workspace yet.'),
            'no_address', 'unknown_contact' => __('This contact has no valid WhatsApp number.'),
            default => __('The message could not be queued.'),
        };
    }

    private function workspaceName(): string
    {
        return $this->settings->current()->business_name ?? $this->context->require()->name;
    }
}
