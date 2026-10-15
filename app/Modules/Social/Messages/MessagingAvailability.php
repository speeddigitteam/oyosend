<?php

declare(strict_types=1);

namespace App\Modules\Social\Messages;

use App\Modules\Social\Messages\Models\DirectConversation;

/**
 * Whether direct messages can work at all on this installation, and why not. Honest codes the page turns into a
 * sentence; nothing here is simulated. Everything is off until the operator switches it on (ADR 0031).
 */
final class MessagingAvailability
{
    public const string NOT_ENABLED = 'not_enabled';

    public const string NOT_CONFIGURED = 'not_configured';

    public const string WEBHOOK_NOT_CONFIGURED = 'webhook_not_configured';

    /** Messenger direct messages are switched on by the operator. */
    public static function enabled(): bool
    {
        return config('social.messaging.enabled') === true;
    }

    /** Instagram direct messages are switched on too (they need Messenger's switch as well). */
    public static function instagramEnabled(): bool
    {
        return self::enabled() && config('social.messaging.instagram') === true;
    }

    /** Whether conversations of the platform are captured and shown at all. */
    public static function platformEnabled(string $platform): bool
    {
        return $platform === DirectConversation::INSTAGRAM ? self::instagramEnabled() : self::enabled();
    }

    /** @return list<string> the platforms captured on this installation */
    public static function platforms(): array
    {
        return array_values(array_filter(DirectConversation::PLATFORMS, self::platformEnabled(...)));
    }

    /**
     * The extra permissions the Facebook connect dialog asks for: none while the switch is off, because asking for
     * a permission Meta has not approved breaks the dialog.
     *
     * @return list<string>
     */
    public static function requestedScopes(): array
    {
        if (! self::enabled()) {
            return [];
        }

        return array_values(array_unique([
            ...self::scopes('social.messaging.required_scopes'),
            ...(self::instagramEnabled() ? self::scopes('social.messaging.instagram_scopes') : []),
        ]));
    }

    /** @return list<string> */
    public static function messengerScopes(): array
    {
        return self::scopes('social.messaging.required_scopes');
    }

    /** @return list<string> */
    public static function instagramScopes(): array
    {
        return self::scopes('social.messaging.instagram_scopes');
    }

    /** @return list<string> */
    public static function webhookFields(): array
    {
        return self::scopes('social.messaging.webhook_fields');
    }

    /** The reason message capture cannot be switched on, or null when the operator side is ready. */
    public function operatorProblem(): ?string
    {
        if (! self::enabled()) {
            return self::NOT_ENABLED;
        }

        if (! self::filled(config('social.platforms.facebook.client_id')) || ! self::filled(config('social.platforms.facebook.client_secret'))) {
            return self::NOT_CONFIGURED;
        }

        if (! self::filled(config('social_comments.facebook.verify_token'))) {
            return self::WEBHOOK_NOT_CONFIGURED;
        }

        return null;
    }

    public function operatorMessage(string $code): string
    {
        return match ($code) {
            self::NOT_ENABLED => __('Direct messages are not switched on for this installation. The operator must enable them once the Meta app has the messaging permissions approved.'),
            self::NOT_CONFIGURED => __('Facebook app credentials are not configured on this installation.'),
            default => __('The Facebook webhook verification token is not configured on this installation.'),
        };
    }

    public static function textMax(string $platform): int
    {
        return max(1, (int) config('social.messaging.text_max.'.$platform, $platform === DirectConversation::INSTAGRAM ? 1000 : 2000));
    }

    public static function windowHours(): int
    {
        return max(1, (int) config('social.messaging.reply_window_hours', 24));
    }

    /** @return list<string> */
    private static function scopes(string $key): array
    {
        return array_values(array_filter(array_map('strval', (array) config($key, [])), static fn (string $scope): bool => $scope !== ''));
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
