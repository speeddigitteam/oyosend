<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

use App\Core\Communication\Data\Whatsapp\WhatsappContent;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * One marketing message to one contact, handed to the communication platform's outbox.
 *
 * The address always comes from the contact, never from the caller: consent belongs to the contact.
 */
final readonly class MarketingMessage
{
    public const int MAX_KEY = 119;

    /**
     * @param  string  $module  The calling module's key (`email_marketing`); lower-case, up to 40 characters.
     * @param  string  $idempotencyKey  Identifies THIS intent, e.g. `campaign:{id}:recipient:{id}`, 1 to 119
     *                                  characters. The same key (per module) queues exactly one message.
     * @param  string|null  $subject  Required for email.
     * @param  string  $body  Plain text body, up to 20000 characters. The platform adds the workspace footer
     *                        and the unsubscribe link when it sends.
     * @param  string|null  $campaignId  Your campaign id (scalar reference, never a model).
     * @param  string|null  $recipientId  Your recipient row id.
     * @param  MessagingPurpose  $purpose  `Marketing` (default: consent and quiet hours apply). `Transactional` is
     *                                     allowed for WhatsApp only: a reply inside the 24-hour customer-service
     *                                     window of a conversation the customer started. Consent is not required,
     *                                     suppression of scope `all` still blocks.
     * @param  array<string, mixed>  $providerHints  Provider-specific instructions (an approved WhatsApp
     *                                               template name, language and parameters). Keys are prefixed with the
     *                                               channel (`whatsapp_template`), at most 10, values at most 2000 characters.
     *                                               They cannot override connection settings or the marketing references.
     * @param  string|null  $senderNumberId  WhatsApp: the workspace number to send from (`ChannelNumber::$id`, see
     *                                       `ChannelNumberDirectory`). Null sends from the default number. A number that is
     *                                       unknown, disabled or belongs to another channel answers `sender_number_unavailable`.
     * @param  WhatsappContent|null  $whatsapp  Rich WhatsApp content (template with media/buttons, reply buttons,
     *                                          list, call-to-action, media, product, product list, Flow). `body` stays the
     *                                          text of the message. Every kind but a template is only valid inside the
     *                                          customer's open 24-hour window: the caller must also send the provider
     *                                          hint `whatsapp_window_until`, and without an open window the gateway answers
     *                                          `outside_customer_service_window` and records nothing. WhatsApp only.
     */
    public function __construct(
        public string $module,
        public string $idempotencyKey,
        public MarketingChannel $channel,
        public string $contactId,
        public ?string $subject,
        public string $body,
        public ?string $campaignId = null,
        public ?string $recipientId = null,
        public ?string $locale = null,
        public ?CarbonInterface $sendAt = null,
        public MessagingPurpose $purpose = MessagingPurpose::Marketing,
        public array $providerHints = [],
        public ?string $senderNumberId = null,
        public ?WhatsappContent $whatsapp = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,39}$/', $module) !== 1) {
            throw new InvalidArgumentException('A module key is lower-case words of up to 40 characters.');
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > self::MAX_KEY) {
            throw new InvalidArgumentException('An idempotency key has 1 to 119 characters.');
        }

        if ($contactId === '' || trim($body) === '' || strlen($body) > 20000) {
            throw new InvalidArgumentException('A marketing message needs a contact and a body of up to 20000 characters.');
        }

        if ($channel === MarketingChannel::Email && trim((string) $subject) === '') {
            throw new InvalidArgumentException('An email needs a subject.');
        }

        if ($purpose === MessagingPurpose::Transactional && $channel !== MarketingChannel::Whatsapp) {
            throw new InvalidArgumentException('Only WhatsApp replies may be sent as transactional through the marketing gateway.');
        }

        if ($whatsapp !== null) {
            if ($channel !== MarketingChannel::Whatsapp) {
                throw new InvalidArgumentException('WhatsApp content can only be sent on the WhatsApp channel.');
            }

            $whatsapp->assertBody($body);
        }

        if ($senderNumberId !== null && ($channel === MarketingChannel::Email || preg_match('/^[0-9A-Za-z]{26}$/', $senderNumberId) !== 1)) {
            throw new InvalidArgumentException('A sender number is a workspace number id of a messaging channel.');
        }

        if (count($providerHints) > 10) {
            throw new InvalidArgumentException('At most 10 provider hints.');
        }

        foreach ($providerHints as $key => $value) {
            if (! str_starts_with((string) $key, $channel->value.'_')
                || preg_match('/^[a-z][a-z0-9_]{0,39}$/', (string) $key) !== 1
                || (! is_scalar($value) && $value !== null)
                || (is_string($value) && strlen($value) > 2000)) {
                throw new InvalidArgumentException('A provider hint is a scalar under a key prefixed with its channel.');
            }
        }

        foreach ([$campaignId, $recipientId] as $reference) {
            if ($reference !== null && ($reference === '' || strlen($reference) > 60)) {
                throw new InvalidArgumentException('A reference id has 1 to 60 characters.');
            }
        }
    }
}
