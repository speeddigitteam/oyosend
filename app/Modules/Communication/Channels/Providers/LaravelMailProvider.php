<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Providers;

use App\Modules\Communication\Channels\Contracts\EmailChannel;
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
 * Email through the platform's configured Laravel mailer. Credentials belong to the operator's MAIL_* settings,
 * so there is no per-workspace secret.
 *
 * Honesty: a mailer set to `log` or `array` does not deliver. Outside `testing` and `local` that reads as
 * not configured and messages are refused instead of being "sent" into a log. This adapter receives no delivery
 * receipts, so its messages stay `sent` and never become `delivered`.
 */
final class LaravelMailProvider implements EmailChannel
{
    public const string KEY = 'mail';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Platform mail service';
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

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function state(ChannelConnection $connection): ConfigurationState
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, ['log', 'array'], true) && ! app()->environment(['testing', 'local'])) {
            return ConfigurationState::NotConfigured;
        }

        return $mailer === '' ? ConfigurationState::NotConfigured : ConfigurationState::Configured;
    }

    public function send(OutboundPayload $payload, ChannelConnection $connection, ?ChannelCredentials $credentials): SendResult
    {
        if ($this->state($connection) === ConfigurationState::NotConfigured) {
            return SendResult::rejected('provider_not_configured', 'No mail service is configured.');
        }

        try {
            $sent = Mail::to($payload->to)->locale($payload->locale)->send(new OutboundMail($payload));
        } catch (Throwable $exception) {
            // The class name only: a transport exception message can echo connection details.
            return SendResult::retry('mail_transport_error', class_basename($exception));
        }

        return SendResult::accepted($sent?->getSymfonySentMessage()?->getMessageId());
    }

    public function parseWebhook(string $body, array $headers, string $secret): array
    {
        throw new InvalidWebhookSignature('This provider does not receive webhooks.');
    }
}
