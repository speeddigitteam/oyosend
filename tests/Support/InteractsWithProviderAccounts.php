<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Channels\Data\OutboundPayload;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Models\ChannelConnection;
use App\Modules\Communication\Models\ProviderAccount;
use Tests\TestCase;

/**
 * Operator-managed messaging provider accounts (ADR 0013): ready-made accounts, request payloads and adapter
 * inputs for the platform and communication tests.
 *
 * @mixin TestCase
 */
trait InteractsWithProviderAccounts
{
    public const string TWILIO_SID = 'AC0123456789abcdef0123456789abcdef';

    public const string TWILIO_TOKEN = 'twilio-auth-token-SECRET-1';

    protected function platformUrl(string $path = '/messaging'): string
    {
        return 'http://admin.localhost'.$path;
    }

    /**
     * Store an account directly (what the platform form would have created).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeAccount(array $overrides = []): ProviderAccount
    {
        return ProviderAccount::query()->create([
            'channel' => Channel::Sms,
            'provider' => 'twilio',
            'name' => 'Twilio main '.bin2hex(random_bytes(3)),
            'is_enabled' => true,
            'settings' => ['account_sid' => self::TWILIO_SID, 'from' => '+15005550006'],
            'secrets' => ['secret' => self::TWILIO_TOKEN],
            'tenant_ids' => null,
            ...$overrides,
        ]);
    }

    /**
     * An HTTP SMS gateway account.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $overrides
     */
    protected function makeHttpSmsAccount(array $settings = [], array $overrides = []): ProviderAccount
    {
        return $this->makeAccount([
            'provider' => 'http_sms',
            'name' => 'Gateway '.bin2hex(random_bytes(3)),
            'settings' => [...$this->httpSmsSettings(), ...$settings],
            'secrets' => ['secret' => 'gateway-api-key-SECRET-2'],
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    protected function httpSmsSettings(): array
    {
        return [
            'endpoint' => 'https://sms.gateway-example.com/api/send',
            'method' => 'POST',
            'body_format' => 'form',
            'auth_mode' => 'bearer',
            'to_param' => 'to',
            'to_format' => 'e164',
            'message_param' => 'text',
        ];
    }

    /**
     * A platform form submission for a provider.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function accountInput(string $provider, array $overrides = []): array
    {
        $base = match ($provider) {
            'smtp' => [
                'channel' => 'email', 'provider' => 'smtp', 'name' => 'Main SMTP',
                'settings' => ['host' => 'smtp.mail-example.com', 'port' => 587, 'encryption' => 'starttls', 'username' => 'mailer', 'from_address' => 'no-reply@example.com', 'from_name' => 'Oyosend'],
                'secrets' => ['secret' => 'smtp-password-SECRET-3'],
            ],
            'twilio' => [
                'channel' => 'sms', 'provider' => 'twilio', 'name' => 'Twilio main',
                'settings' => ['account_sid' => self::TWILIO_SID, 'from' => '+15005550006'],
                'secrets' => ['secret' => self::TWILIO_TOKEN],
            ],
            'http_sms' => [
                'channel' => 'sms', 'provider' => 'http_sms', 'name' => 'Local gateway',
                'settings' => $this->httpSmsSettings(),
                'secrets' => ['secret' => 'gateway-api-key-SECRET-2'],
            ],
            'whatsapp_cloud' => [
                'channel' => 'whatsapp', 'provider' => 'whatsapp_cloud', 'name' => 'Meta app',
                'secrets' => ['secret' => 'wa-access-token-SECRET-4', 'webhook_secret' => 'wa-app-secret-SECRET-5', 'verify_token' => 'wa-verify-SECRET-6'],
            ],
        };

        return [...$base, ...$overrides];
    }

    /** @param array<string, mixed> $meta */
    protected function smsPayload(string $to = '+8801711000000', string $body = 'Hello there', array $meta = []): OutboundPayload
    {
        return new OutboundPayload('01TESTMESSAGE', Channel::Sms, $to, null, $body, 'en', meta: $meta);
    }

    protected function smsConnection(string $provider): ChannelConnection
    {
        return new ChannelConnection(['channel' => Channel::Sms, 'provider' => $provider, 'is_enabled' => true]);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function credentials(string $secret, array $settings = []): ChannelCredentials
    {
        return new ChannelCredentials($secret, $settings);
    }
}
