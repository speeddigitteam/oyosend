<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Providers;

use App\Modules\Communication\Channels\Contracts\EmailChannel;
use App\Modules\Communication\Channels\Contracts\PlatformAccountProvider;
use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Channels\Data\OutboundPayload;
use App\Modules\Communication\Channels\Data\SendResult;
use App\Modules\Communication\Channels\Exceptions\InvalidWebhookSignature;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConfigurationState;
use App\Modules\Communication\Mail\OutboundMail;
use App\Modules\Communication\Models\ChannelConnection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email through an SMTP server the operator added as a platform account (host, port, user and from address are
 * the account's settings, the password is its encrypted secret). Several accounts can exist; a workspace picks one.
 *
 * Like the platform mailer it receives no delivery receipts: accepted messages stay `sent`.
 */
final class SmtpProvider implements EmailChannel, PlatformAccountProvider
{
    public const string KEY = 'smtp';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'SMTP server';
    }

    public function channel(): Channel
    {
        return Channel::Email;
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
            ['key' => 'host', 'label' => 'SMTP host', 'type' => 'text', 'required' => true, 'maxLength' => 190],
            ['key' => 'port', 'label' => 'Port', 'type' => 'integer', 'required' => true],
            ['key' => 'encryption', 'label' => 'Encryption', 'type' => 'select', 'required' => true, 'options' => ['starttls', 'ssl']],
            ['key' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => false, 'maxLength' => 190],
            ['key' => 'secret', 'label' => 'Password', 'type' => 'secret', 'required' => true],
            ['key' => 'from_address', 'label' => 'From address', 'type' => 'text', 'required' => true, 'maxLength' => 190],
            ['key' => 'from_name', 'label' => 'From name', 'type' => 'text', 'required' => false, 'maxLength' => 120],
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
        $host = $settings['host'] ?? null;
        $from = $settings['from_address'] ?? null;

        if ($credentials === null || ! is_string($host) || $host === '' || ! is_string($from) || $from === '') {
            return SendResult::rejected('provider_not_configured', 'The SMTP account is not configured.');
        }

        $username = is_string($settings['username'] ?? null) && $settings['username'] !== '' ? $settings['username'] : null;
        $fromName = is_string($settings['from_name'] ?? null) && $settings['from_name'] !== '' ? $settings['from_name'] : null;

        try {
            $mailer = Mail::build([
                'transport' => 'smtp',
                'scheme' => ($settings['encryption'] ?? 'starttls') === 'ssl' ? 'smtps' : 'smtp',
                'host' => $host,
                'port' => (int) ($settings['port'] ?? 587),
                'username' => $username,
                'password' => $username === null ? null : $credentials->secret(),
                'timeout' => 15,
            ]);

            $sent = $mailer->to($payload->to)->locale($payload->locale)->send(new OutboundMail($payload, $from, $fromName));
        } catch (Throwable $exception) {
            // The class name only: a transport exception message can echo the host and the login.
            return SendResult::retry('smtp_transport_error', class_basename($exception));
        }

        return SendResult::accepted($sent?->getSymfonySentMessage()?->getMessageId());
    }

    public function parseWebhook(string $body, array $headers, string $secret): array
    {
        throw new InvalidWebhookSignature('This provider does not receive webhooks.');
    }
}
