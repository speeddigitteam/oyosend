<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Providers;

use App\Modules\Communication\Channels\Contracts\PlatformAccountProvider;
use App\Modules\Communication\Channels\Contracts\VerifiesWebhookSubscription;
use App\Modules\Communication\Channels\Contracts\WhatsAppChannel;
use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Channels\Data\OutboundPayload;
use App\Modules\Communication\Channels\Data\ProviderEvent;
use App\Modules\Communication\Channels\Data\SendResult;
use App\Modules\Communication\Channels\Exceptions\InvalidWebhookSignature;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConfigurationState;
use App\Modules\Communication\Enums\ProviderEventType;
use App\Modules\Communication\Models\ChannelConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * The official WhatsApp Business Platform (Cloud API, Meta Graph API).
 *
 * Honest by construction: without a `phone_number_id` and a resolvable access-token reference the connection is
 * `not_configured` and every send is rejected. The access token is the `credential_ref` secret and the app
 * secret (webhook signature, `X-Hub-Signature-256`) is the `webhook_secret_ref` secret; neither is stored in the
 * database, logged or placed in an exception.
 *
 * What it sends (`OutboundPayload::$meta` hints, set by the WhatsApp Business app through the channel gateway):
 *  - `whatsapp_template` + `whatsapp_language` (+ `whatsapp_params`, a JSON list of body parameter strings):
 *    an approved template message. Allowed any time.
 *  - otherwise a free-form text message, which Meta only accepts inside the 24-hour customer-service window.
 *    `whatsapp_window_until` (ISO-8601) makes this adapter refuse locally once the window has closed.
 *
 * NOT TESTED AGAINST LIVE META: it is covered by HTTP-faked tests only. Using it for real needs the operator's
 * Meta app, a verified business, a registered phone number, approved templates and app review (docs/WHATSAPP.md).
 */
final class WhatsAppCloudProvider implements PlatformAccountProvider, VerifiesWebhookSubscription, WhatsAppChannel
{
    public const string KEY = 'whatsapp_cloud';

    public const int MAX_TEXT = 4096;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'WhatsApp Business Platform (Cloud API)';
    }

    public function channel(): Channel
    {
        return Channel::Whatsapp;
    }

    public function isTestDouble(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return [
            ['key' => 'phone_number_id', 'label' => 'Phone number ID', 'type' => 'text', 'required' => true, 'maxLength' => 32, 'account' => true],
            ['key' => 'business_account_id', 'label' => 'WhatsApp Business Account ID', 'type' => 'text', 'required' => false, 'maxLength' => 32],
            ['key' => 'graph_api_version', 'label' => 'Graph API version', 'type' => 'text', 'required' => false, 'maxLength' => 8],
        ];
    }

    public function accountFields(): array
    {
        // The phone number id stays the workspace's (one workspace per number); the operator's account holds
        // the credentials of the Meta app that owns the numbers.
        return [
            ['key' => 'secret', 'label' => 'System user access token', 'type' => 'secret', 'required' => true],
            ['key' => 'webhook_secret', 'label' => 'App secret (webhook signature)', 'type' => 'secret', 'required' => false],
            ['key' => 'verify_token', 'label' => 'Webhook verify token', 'type' => 'secret', 'required' => false],
        ];
    }

    public function supportsTestSend(): bool
    {
        return false;
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function state(ChannelConnection $connection): ConfigurationState
    {
        $settings = $connection->settings ?? [];

        return $connection->credential_ref !== null
            && is_string($settings['phone_number_id'] ?? null)
            && preg_match('/^\d{5,32}$/', $settings['phone_number_id']) === 1
                ? ConfigurationState::Configured
                : ConfigurationState::NotConfigured;
    }

    public function send(OutboundPayload $payload, ChannelConnection $connection, ?ChannelCredentials $credentials): SendResult
    {
        if ($credentials === null || $this->state($connection) === ConfigurationState::NotConfigured) {
            return SendResult::rejected('provider_not_configured', 'WhatsApp is not configured for this workspace.');
        }

        $settings = $connection->settings ?? [];
        $phoneNumberId = (string) $settings['phone_number_id'];
        $version = is_string($settings['graph_api_version'] ?? null) && preg_match('/^v\d{1,2}\.\d$/', $settings['graph_api_version']) === 1
            ? $settings['graph_api_version']
            : (string) config('communication.whatsapp.api_version', 'v21.0');
        $to = ltrim($payload->to, '+');

        if (preg_match('/^[1-9]\d{6,14}$/', $to) !== 1) {
            return SendResult::rejected('invalid_recipient', 'The number is not a valid international phone number.');
        }

        $template = $payload->meta['whatsapp_template'] ?? null;

        if (is_string($template) && $template !== '') {
            $language = (string) ($payload->meta['whatsapp_language'] ?? '');

            if (preg_match('/^[a-z0-9_]{1,512}$/', $template) !== 1 || preg_match('/^[a-z]{2,3}(_[A-Za-z]{2,4})?$/', $language) !== 1) {
                return SendResult::rejected('invalid_template', 'The template name or language is not valid.');
            }

            $parameters = $this->parameters($payload);
            $body = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $template,
                    'language' => ['code' => $language],
                    ...($parameters === [] ? [] : ['components' => [[
                        'type' => 'body',
                        'parameters' => array_map(static fn (string $text): array => ['type' => 'text', 'text' => $text], $parameters),
                    ]]]),
                ],
            ];
        } else {
            $until = $payload->meta['whatsapp_window_until'] ?? null;

            if (is_string($until) && CarbonImmutable::parse($until)->isPast()) {
                return SendResult::rejected('outside_customer_service_window', 'The 24-hour customer-service window has closed; only a template can be sent.');
            }

            $text = mb_substr($payload->body, 0, self::MAX_TEXT);

            if (trim($text) === '') {
                return SendResult::rejected('empty_message', 'There is nothing to send.');
            }

            $body = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => $text],
            ];
        }

        $base = rtrim((string) config('communication.whatsapp.graph_base_url', 'https://graph.facebook.com'), '/');

        try {
            $response = Http::withToken($credentials->secret())
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post($base.'/'.$version.'/'.$phoneNumberId.'/messages', $body);
        } catch (ConnectionException) {
            return SendResult::retry('provider_unreachable', 'The WhatsApp service could not be reached.');
        } catch (Throwable $exception) {
            return SendResult::retry('provider_error', class_basename($exception));
        }

        if ($response->successful()) {
            $id = $response->json('messages.0.id');

            return is_string($id) && $id !== ''
                ? SendResult::accepted($id)
                : SendResult::retry('provider_malformed_response', 'The WhatsApp service gave no message id.');
        }

        return $this->failure($response->status(), $response->json('error.code'), $response->json('error.error_data.details') ?? $response->json('error.message'));
    }

    public function parseWebhook(string $body, array $headers, #[SensitiveParameter] string $secret): array
    {
        $this->assertSignature($body, $headers['x-hub-signature-256'] ?? null, $secret);

        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ($decoded['object'] ?? null) !== 'whatsapp_business_account' || ! is_array($decoded['entry'] ?? null)) {
            return [];
        }

        $events = [];

        foreach ($decoded['entry'] as $entry) {
            if (! is_array($entry) || ! is_array($entry['changes'] ?? null)) {
                continue;
            }

            $accountId = isset($entry['id']) && is_scalar($entry['id']) ? (string) $entry['id'] : null;
            $entryTime = isset($entry['time']) && is_scalar($entry['time']) ? (string) $entry['time'] : '0';

            foreach ($entry['changes'] as $change) {
                $value = is_array($change) && is_array($change['value'] ?? null) ? $change['value'] : null;
                $field = is_array($change) ? ($change['field'] ?? null) : null;

                if ($value === null) {
                    continue;
                }

                if ($field === 'messages') {
                    $phoneNumberId = is_array($value['metadata'] ?? null) && isset($value['metadata']['phone_number_id'])
                        ? (string) $value['metadata']['phone_number_id']
                        : '-';
                    $accounts = ['phone_number_id' => $phoneNumberId];

                    array_push($events, ...$this->messageEvents($value, $accounts), ...$this->statusEvents($value, $accounts));
                } elseif ($field === 'message_template_status_update') {
                    $event = $this->templateEvent($value, $accountId, $entryTime);

                    if ($event !== null) {
                        $events[] = $event;
                    }
                }
            }
        }

        return $events;
    }

    public function answerHandshake(array $query, #[SensitiveParameter] string $verifyToken): ?string
    {
        $token = $query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '';
        $mode = $query['hub_mode'] ?? $query['hub.mode'] ?? '';
        $challenge = $query['hub_challenge'] ?? $query['hub.challenge'] ?? '';

        if ($mode !== 'subscribe' || $verifyToken === '' || ! hash_equals($verifyToken, $token)) {
            return null;
        }

        // The challenge is echoed as plain text; Meta's own values are numeric. Refuse anything else.
        return preg_match('/^[0-9A-Za-z_-]{1,64}$/', $challenge) === 1 ? $challenge : null;
    }

    /** @throws InvalidWebhookSignature */
    private function assertSignature(string $body, ?string $header, #[SensitiveParameter] string $secret): void
    {
        $header = $header === null ? '' : strtolower(trim($header));

        if ($secret === '' || ! str_starts_with($header, 'sha256=') || ! hash_equals(hash_hmac('sha256', $body, $secret), substr($header, 7))) {
            throw new InvalidWebhookSignature('The webhook signature is not valid.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  array<string, string>  $accounts
     * @return list<ProviderEvent>
     */
    private function messageEvents(array $value, array $accounts): array
    {
        $names = [];

        foreach ((array) ($value['contacts'] ?? []) as $contact) {
            if (is_array($contact) && isset($contact['wa_id'])) {
                $names[(string) $contact['wa_id']] = is_array($contact['profile'] ?? null) && is_string($contact['profile']['name'] ?? null)
                    ? mb_substr($contact['profile']['name'], 0, 120)
                    : null;
            }
        }

        $events = [];

        foreach ((array) ($value['messages'] ?? []) as $message) {
            if (! is_array($message) || ! isset($message['id'], $message['from']) || ! is_string($message['id'])) {
                continue;
            }

            $from = (string) $message['from'];
            $type = is_string($message['type'] ?? null) ? $message['type'] : 'unsupported';
            $meta = ['message_type' => mb_substr($type, 0, 20), 'timestamp' => (string) ($message['timestamp'] ?? '')];

            if (($names[$from] ?? null) !== null) {
                $meta['profile_name'] = $names[$from];
            }

            $text = null;

            if ($type === 'text' && is_array($message['text'] ?? null)) {
                $text = (string) ($message['text']['body'] ?? '');
            } elseif ($type === 'button' && is_array($message['button'] ?? null)) {
                $text = (string) ($message['button']['text'] ?? '');
            } elseif ($type === 'interactive' && is_array($message['interactive'] ?? null)) {
                $reply = $message['interactive']['button_reply'] ?? $message['interactive']['list_reply'] ?? null;
                $text = is_array($reply) ? (string) ($reply['title'] ?? '') : null;
            } elseif (in_array($type, ['image', 'document', 'audio', 'video', 'sticker'], true) && is_array($message[$type] ?? null)) {
                $media = $message[$type];
                $meta['media_mime'] = mb_substr((string) ($media['mime_type'] ?? ''), 0, 100);
                $meta['media_id'] = mb_substr((string) ($media['id'] ?? ''), 0, 100);
                $caption = $media['caption'] ?? null;
                $text = is_string($caption) ? $caption : null;
            }

            $events[] = new ProviderEvent(
                eventId: mb_substr($message['id'], 0, 150),
                type: ProviderEventType::Inbound,
                providerMessageId: null,
                address: '+'.preg_replace('/\D+/', '', $from),
                text: $text === null ? null : mb_substr($text, 0, self::MAX_TEXT),
                accounts: $accounts,
                meta: $meta,
            );
        }

        return $events;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  array<string, string>  $accounts
     * @return list<ProviderEvent>
     */
    private function statusEvents(array $value, array $accounts): array
    {
        $events = [];

        foreach ((array) ($value['statuses'] ?? []) as $status) {
            if (! is_array($status) || ! isset($status['id'], $status['status']) || ! is_string($status['id']) || ! is_string($status['status'])) {
                continue;
            }

            $type = match ($status['status']) {
                'delivered' => ProviderEventType::Delivered,
                'read' => ProviderEventType::Read,
                'failed' => ProviderEventType::Failed,
                default => null,
            };

            if ($type === null) {
                continue;
            }

            $meta = ['timestamp' => (string) ($status['timestamp'] ?? '')];
            $pricing = is_array($status['pricing'] ?? null) ? $status['pricing'] : [];
            $conversation = is_array($status['conversation'] ?? null) ? $status['conversation'] : [];

            if (array_key_exists('billable', $pricing)) {
                $meta['billable'] = (bool) $pricing['billable'];
            }

            if (is_string($pricing['category'] ?? null)) {
                $meta['pricing_category'] = mb_substr($pricing['category'], 0, 30);
            }

            if (is_array($conversation['origin'] ?? null) && is_string($conversation['origin']['type'] ?? null)) {
                $meta['conversation_origin'] = mb_substr($conversation['origin']['type'], 0, 30);
            }

            $reason = null;

            if (is_array($status['errors'][0] ?? null)) {
                $error = $status['errors'][0];
                $reason = trim((string) ($error['code'] ?? '').' '.(string) ($error['title'] ?? ''));
                $meta['error_code'] = (string) ($error['code'] ?? '');
            }

            $events[] = new ProviderEvent(
                eventId: mb_substr($status['id'].':'.$status['status'], 0, 190),
                type: $type,
                providerMessageId: $status['id'],
                address: isset($status['recipient_id']) ? '+'.preg_replace('/\D+/', '', (string) $status['recipient_id']) : null,
                reason: $reason === '' ? null : $reason,
                accounts: $accounts,
                meta: $meta,
            );
        }

        return $events;
    }

    /** @param array<array-key, mixed> $value */
    private function templateEvent(array $value, ?string $accountId, string $entryTime): ?ProviderEvent
    {
        $name = $value['message_template_name'] ?? null;
        $language = $value['message_template_language'] ?? null;
        $event = $value['event'] ?? null;

        if (! is_string($name) || ! is_string($language) || ! is_string($event)) {
            return null;
        }

        $id = isset($value['message_template_id']) && is_scalar($value['message_template_id']) ? (string) $value['message_template_id'] : $name.':'.$language;

        return new ProviderEvent(
            eventId: mb_substr('tpl:'.$id.':'.strtolower($event).':'.$entryTime, 0, 190),
            type: ProviderEventType::TemplateStatus,
            reason: is_string($value['reason'] ?? null) && $value['reason'] !== 'NONE' ? mb_substr($value['reason'], 0, 200) : null,
            accounts: ['business_account_id' => $accountId ?? '-'],
            meta: [
                'template_name' => mb_substr($name, 0, 512),
                'template_language' => mb_substr($language, 0, 20),
                'template_status' => strtolower(mb_substr($event, 0, 30)),
                'provider_template_id' => mb_substr($id, 0, 64),
            ],
        );
    }

    /** @return list<string> */
    private function parameters(OutboundPayload $payload): array
    {
        $raw = $payload->meta['whatsapp_params'] ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($decoded)) {
            return [];
        }

        $parameters = [];

        foreach (array_slice($decoded, 0, 20) as $value) {
            // Meta rejects empty parameters and parameters with newlines or four spaces in a row.
            $text = trim(preg_replace('/\s+/u', ' ', (string) (is_scalar($value) ? $value : '')) ?? '');
            $parameters[] = $text === '' ? '-' : mb_substr($text, 0, 1024);
        }

        return $parameters;
    }

    private function failure(int $status, mixed $code, mixed $detail): SendResult
    {
        $code = is_numeric($code) ? (int) $code : null;
        $reason = is_string($detail) ? mb_substr($detail, 0, 200) : '';

        return match (true) {
            $code === 131047 || $code === 131051 => SendResult::rejected('outside_customer_service_window', $reason),
            $code === 131026 || $code === 131021 => SendResult::rejected('invalid_recipient', $reason),
            $code === 131048 || $code === 131056 || $code === 130429 || $code === 80007 || $code === 4 || $code === 17 => SendResult::retry('provider_rate_limited', $reason),
            in_array($code, [132000, 132001, 132005, 132007, 132012], true) => SendResult::rejected('invalid_template', $reason),
            $code === 190 || $status === 401 => SendResult::rejected('provider_authentication_failed', 'The WhatsApp access token was refused.'),
            $status === 429 || $status >= 500 => SendResult::retry('provider_unavailable', $reason),
            default => SendResult::rejected('provider_rejected', $reason),
        };
    }
}
