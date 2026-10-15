<?php

declare(strict_types=1);

namespace App\Core\Settings;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Resolves and manages the operator-set credentials (and a few non-secret switches) for the Social
 * platform apps and the Meta Ads app. An operator sets them in the Super Admin panel (stored encrypted and write-only in
 * `integration_credentials`); the environment (config/*.php env defaults) is the fallback default
 * (docs/adr/0029-social-and-meta-ads-panel-credentials.md, following ADR 0028 / ADR 0013).
 *
 * Rather than edit the many `config('social.…')` / `config('meta_ads.…')` readers, {@see apply()}
 * overlays the operator values onto the live config repository at boot: a stored, non-empty value
 * for an enabled row replaces the env default for its config key, so every existing reader
 * transparently sees the panel value. A key with no stored value — or a disabled row, or an
 * unreadable table (bootstrap, a missing migration) — keeps the env default. Decrypted secrets are
 * read only here and are never cached across requests, logged or returned to a page.
 */
final class IntegrationCredentials
{
    /**
     * The managed integrations. For each (group, name): the write-only secret fields, the shown-back
     * public fields and the non-secret on/off switches (`flagFields`, stored as booleans in `settings`),
     * each mapped to the config key it overlays.
     *
     * @var array<string, array<string, array{secretFields: array<string, string>, publicFields: array<string, string>, flagFields: array<string, string>}>>
     */
    public const array SCHEMA = [
        'social' => [
            'facebook' => [
                'secretFields' => [
                    'client_secret' => 'social.platforms.facebook.client_secret',
                    'webhook_verify_token' => 'social.lead_ads.webhook_verify_token',
                ],
                'publicFields' => [
                    'client_id' => 'social.platforms.facebook.client_id',
                ],
                'flagFields' => [],
            ],
            'linkedin' => [
                'secretFields' => [
                    'client_secret' => 'social.platforms.linkedin.client_secret',
                ],
                'publicFields' => [
                    'client_id' => 'social.platforms.linkedin.client_id',
                ],
                'flagFields' => [],
            ],
            'x' => [
                'secretFields' => [
                    'client_secret' => 'social.platforms.x.client_secret',
                ],
                'publicFields' => [
                    'client_id' => 'social.platforms.x.client_id',
                ],
                'flagFields' => [],
            ],
            // Instagram publishing reuses the Facebook (Meta) app above; it has only an on/off switch (ADR 0033).
            'instagram' => [
                'secretFields' => [],
                'publicFields' => [],
                'flagFields' => [
                    'publishing' => 'social.platforms.instagram.publishing',
                ],
            ],
            // TikTok names its public identifier the "client key"; it overlays `client_id` (config/social.php).
            'tiktok' => [
                'secretFields' => [
                    'client_secret' => 'social.platforms.tiktok.client_secret',
                ],
                'publicFields' => [
                    'client_key' => 'social.platforms.tiktok.client_id',
                ],
                'flagFields' => [],
            ],
            'youtube' => [
                'secretFields' => [
                    'client_secret' => 'social.platforms.youtube.client_secret',
                ],
                'publicFields' => [
                    'client_id' => 'social.platforms.youtube.client_id',
                ],
                'flagFields' => [],
            ],
            'pinterest' => [
                'secretFields' => [
                    'client_secret' => 'social.platforms.pinterest.client_secret',
                ],
                'publicFields' => [
                    'client_id' => 'social.platforms.pinterest.client_id',
                ],
                'flagFields' => [],
            ],
        ],
        'meta_ads' => [
            'app' => [
                'secretFields' => [
                    'app_secret' => 'meta_ads.app_secret',
                ],
                'publicFields' => [
                    'app_id' => 'meta_ads.app_id',
                ],
                'flagFields' => [],
            ],
        ],
    ];

    /** @var array<string, IntegrationCredential>|null per-request memo, keyed "group/name" */
    private ?array $rows = null;

    /**
     * Overlay the operator-set credentials onto the live config repository. Safe to call during
     * boot: a missing table or unreachable database leaves every env default untouched.
     */
    public function apply(ConfigRepository $config): void
    {
        $rows = $this->rows();

        foreach (self::SCHEMA as $group => $names) {
            foreach ($names as $name => $spec) {
                $row = $rows[$group.'/'.$name] ?? null;

                if ($row === null || ! $row->is_enabled) {
                    continue;
                }

                $secrets = $row->secrets ?? [];
                foreach ($spec['secretFields'] as $field => $configKey) {
                    $value = $secrets[$field] ?? null;
                    if (is_string($value) && $value !== '') {
                        $config->set($configKey, $value);
                    }
                }

                $public = $row->settings ?? [];
                foreach ($spec['publicFields'] as $field => $configKey) {
                    $value = $public[$field] ?? null;
                    if (is_string($value) && $value !== '') {
                        $config->set($configKey, $value);
                    }
                }

                // A stored switch always overlays (off is a real choice); an unset one keeps the env default.
                foreach ($spec['flagFields'] as $field => $configKey) {
                    $value = $public[$field] ?? null;
                    if (is_bool($value)) {
                        $config->set($configKey, $value);
                    }
                }
            }
        }
    }

    /**
     * Save an operator's credentials for an integration. A blank secret field keeps the stored value
     * (so the operator can change a public id without re-typing a secret); public fields overwrite,
     * including to empty, because they are not secret and are shown back. A switch not given is off.
     *
     * @param  array<string, string>  $secrets
     * @param  array<string, string>  $public
     * @param  array<string, bool>  $flags
     */
    public function save(string $group, string $name, array $secrets, array $public, bool $isEnabled, array $flags = []): void
    {
        $spec = self::SCHEMA[$group][$name] ?? throw new InvalidArgumentException("Unknown integration [{$group}/{$name}].");

        $row = IntegrationCredential::query()->where('group', $group)->where('name', $name)->first();
        $existing = $row === null ? [] : ($row->secrets ?? []);

        $mergedSecrets = [];
        foreach (array_keys($spec['secretFields']) as $field) {
            $value = trim((string) ($secrets[$field] ?? ''));
            $kept = (string) ($existing[$field] ?? '');
            $resolved = $value !== '' ? $value : $kept;

            if ($resolved !== '') {
                $mergedSecrets[$field] = $resolved;
            }
        }

        $mergedPublic = [];
        foreach (array_keys($spec['publicFields']) as $field) {
            $value = trim((string) ($public[$field] ?? ''));
            if ($value !== '') {
                $mergedPublic[$field] = $value;
            }
        }

        foreach (array_keys($spec['flagFields']) as $field) {
            $mergedPublic[$field] = (bool) ($flags[$field] ?? false);
        }

        IntegrationCredential::query()->updateOrCreate(
            ['group' => $group, 'name' => $name],
            ['secrets' => $mergedSecrets, 'settings' => $mergedPublic, 'is_enabled' => $isEnabled],
        );
    }

    /**
     * Forget an operator's credentials for an integration: it falls back to the environment.
     */
    public function clear(string $group, string $name): void
    {
        IntegrationCredential::query()->where('group', $group)->where('name', $name)->delete();
    }

    /**
     * What the panel may show about an integration. Never includes a secret value: only whether it
     * is managed and enabled, the non-secret public values, which secret fields hold a value, and
     * when it was last changed.
     *
     * @return array{managed: bool, enabled: bool, publicValues: array<string, string>, flagValues: array<string, bool>, storedSecretFields: list<string>, updatedAt: ?string}
     */
    public function status(string $group, string $name): array
    {
        $spec = self::SCHEMA[$group][$name] ?? ['secretFields' => [], 'publicFields' => [], 'flagFields' => []];
        $row = IntegrationCredential::query()->where('group', $group)->where('name', $name)->first();

        if ($row === null) {
            return ['managed' => false, 'enabled' => false, 'publicValues' => [], 'flagValues' => [], 'storedSecretFields' => [], 'updatedAt' => null];
        }

        $held = $row->secrets ?? [];
        $storedSecretFields = array_values(array_filter(
            array_keys($spec['secretFields']),
            static fn (string $field): bool => is_string($held[$field] ?? null) && $held[$field] !== '',
        ));

        $settings = $row->settings ?? [];
        $publicValues = [];
        foreach (array_keys($spec['publicFields']) as $field) {
            $value = $settings[$field] ?? null;
            $publicValues[$field] = is_string($value) ? $value : '';
        }

        $flagValues = [];
        foreach (array_keys($spec['flagFields']) as $field) {
            $flagValues[$field] = ($settings[$field] ?? false) === true;
        }

        return [
            'managed' => true,
            'enabled' => $row->is_enabled,
            'publicValues' => $publicValues,
            'flagValues' => $flagValues,
            'storedSecretFields' => $storedSecretFields,
            'updatedAt' => $row->updated_at instanceof Carbon ? $row->updated_at->toIso8601String() : null,
        ];
    }

    /**
     * @return array<string, IntegrationCredential>
     */
    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        try {
            $rows = [];
            foreach (IntegrationCredential::query()->get() as $row) {
                $rows[$row->group.'/'.$row->name] = $row;
            }

            return $this->rows = $rows;
        } catch (Throwable) {
            // The table does not exist yet (bootstrap, a fresh install mid-migration) or the database
            // is unreachable: leave the environment defaults rather than failing to boot.
            return $this->rows = [];
        }
    }
}
