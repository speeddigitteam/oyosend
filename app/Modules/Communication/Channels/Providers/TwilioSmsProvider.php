<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Providers;

use App\Modules\Communication\Channels\Contracts\PlatformAccountProvider;
use App\Modules\Communication\Channels\Contracts\SmsChannel;
use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Channels\Data\OutboundPayload;
use App\Modules\Communication\Channels\Data\SendResult;
use App\Modules\Communication\Channels\Exceptions\InvalidWebhookSignature;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConfigurationState;
use App\Modules\Communication\Models\ChannelConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * SMS through Twilio's Programmable Messaging REST API. The Account SID and the sender are the account's
 * settings, the Auth Token is its encrypted secret. Delivery receipts are not consumed: accepted messages stay
 * `sent`. NOT TESTED AGAINST LIVE TWILIO: covered by HTTP-faked tests only.
 */
final class TwilioSmsProvider implements PlatformAccountProvider, SmsChannel
{
    public const string KEY = 'twilio';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Twilio SMS';
    }

    public function channel(): Channel
    {
        return Channel::Sms;
    }

    public function isTestDouble(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return [];
    }

    public function accountFields(): array
    {
        return [
            ['key' => 'account_sid', 'label' => 'Account SID', 'type' => 'text', 'required' => true, 'maxLength' => 34],
            ['key' => 'secret', 'label' => 'Auth token', 'type' => 'secret', 'required' => true],
            ['key' => 'from', 'label' => 'Sender number or Messaging Service SID', 'type' => 'text', 'required' => true, 'maxLength' => 40],
        ];
    }

    public function supportsTestSend(): bool
    {
        return true;
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function state(ChannelConnection $connection): ConfigurationState
    {
        return $connection->credential_ref === null ? ConfigurationState::NotConfigured : ConfigurationState::Configured;
    }

    public function send(OutboundPayload $payload, ChannelConnection $connection, ?ChannelCredentials $credentials): SendResult
    {
        $settings = $credentials?->settings() ?? [];
        $sid = $settings['account_sid'] ?? null;
        $from = $settings['from'] ?? null;

        if ($credentials === null || ! is_string($sid) || preg_match('/^AC[0-9a-fA-F]{32}$/', $sid) !== 1 || ! is_string($from) || $from === '') {
            return SendResult::rejected('provider_not_configured', 'The Twilio account is not configured.');
        }

        if (preg_match('/^\+[1-9]\d{6,14}$/', $payload->to) !== 1) {
            return SendResult::rejected('invalid_recipient', 'The number is not a valid international phone number.');
        }

        if (trim($payload->body) === '') {
            return SendResult::rejected('empty_message', 'There is nothing to send.');
        }

        $base = rtrim((string) config('communication.sms.twilio_base_url', 'https://api.twilio.com'), '/');
        $sender = str_starts_with($from, 'MG') ? ['MessagingServiceSid' => $from] : ['From' => $from];

        try {
            $response = Http::withBasicAuth($sid, $credentials->secret())
                ->acceptJson()
                ->asForm()
                ->connectTimeout(5)
                ->timeout(15)
                ->post($base.'/2010-04-01/Accounts/'.$sid.'/Messages.json', [
                    'To' => $payload->to,
                    'Body' => mb_substr($payload->body, 0, 1600),
                    ...$sender,
                ]);
        } catch (ConnectionException) {
            return SendResult::retry('provider_unreachable', 'Twilio could not be reached.');
        } catch (Throwable $exception) {
            return SendResult::retry('provider_error', class_basename($exception));
        }

        if ($response->successful()) {
            $id = $response->json('sid');

            return is_string($id) && $id !== ''
                ? SendResult::accepted($id)
                : SendResult::retry('provider_malformed_response', 'Twilio gave no message id.');
        }

        $status = $response->status();

        if ($status === 429 || $status >= 500) {
            return SendResult::retry('provider_unavailable', 'Twilio is busy or unavailable.');
        }

        if ($status === 401 || $status === 403) {
            return SendResult::rejected('provider_credentials_rejected', 'Twilio refused the credentials.');
        }

        $code = $response->json('code');

        return SendResult::rejected('provider_rejected', 'Twilio refused the message'.(is_int($code) ? ' (code '.$code.').' : '.'));
    }

    public function parseWebhook(string $body, array $headers, string $secret): array
    {
        throw new InvalidWebhookSignature('This provider does not receive webhooks.');
    }
}
