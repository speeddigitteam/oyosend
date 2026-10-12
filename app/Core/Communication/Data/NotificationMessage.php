<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

use InvalidArgumentException;

/**
 * What a module asks the communication platform to tell workspace members.
 *
 * It carries translation KEYS (the English text, with `:placeholder` slots) and scalar parameters, never
 * rendered prose, so every reader sees it in their own language. `linkRoute` is a workspace route name; it is
 * resolved with `route()` when shown and silently dropped if it no longer exists.
 */
final readonly class NotificationMessage
{
    public const array SEVERITIES = ['info', 'success', 'warning', 'critical'];

    /**
     * @param  string  $category  `system`, `assignments`, `reminders`, `approvals`, `billing`, `mentions`, or a module's own `{module}.{name}`.
     * @param  string  $titleKey  English translation key, up to 255 characters.
     * @param  array<string, scalar|null>  $params  Values for the `:placeholder` slots of the keys.
     * @param  array<string, scalar>  $linkParams  Route parameters (ids), never the tenant.
     * @param  string|null  $dedupeKey  The same key for the same member records the notification once.
     */
    public function __construct(
        public string $category,
        public string $titleKey,
        public array $params = [],
        public ?string $bodyKey = null,
        public ?string $linkRoute = null,
        public array $linkParams = [],
        public string $severity = 'info',
        public ?string $dedupeKey = null,
        public ?string $sourceModule = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.]{0,39}$/', $category) !== 1) {
            throw new InvalidArgumentException('A notification category is lower-case words, dots and underscores.');
        }

        if ($titleKey === '' || strlen($titleKey) > 255 || ($bodyKey !== null && strlen($bodyKey) > 500)) {
            throw new InvalidArgumentException('A notification needs a title key of at most 255 characters.');
        }

        if (! in_array($severity, self::SEVERITIES, true)) {
            throw new InvalidArgumentException('Unknown notification severity.');
        }

        if ($dedupeKey !== null && ($dedupeKey === '' || strlen($dedupeKey) > 160)) {
            throw new InvalidArgumentException('A dedupe key has 1 to 160 characters.');
        }
    }
}
