<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Settings\IntegrationCredentials;
use App\Core\Support\Toast;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator-managed credentials for the Social platform apps (Facebook, LinkedIn, X, TikTok, YouTube,
 * Pinterest; Instagram reuses the Facebook app and has only a publishing switch) and the Meta Ads app. The operator types them in the Super Admin panel; secrets are stored encrypted and are
 * write-only (never returned to the page or logged), with the environment as the fallback default
 * (docs/adr/0029-social-and-meta-ads-panel-credentials.md, following ADR 0028 / ADR 0013). Public
 * identifiers (client_id / app_id) are not secret and are shown back so they can match the OAuth
 * redirect URIs.
 */
final class IntegrationController
{
    public function index(IntegrationCredentials $credentials, ConfigRepository $config): Response
    {
        $integrations = [];

        foreach (IntegrationCredentials::SCHEMA as $group => $names) {
            foreach ($names as $name => $spec) {
                $status = $credentials->status($group, $name);

                $integrations[] = [
                    'group' => $group,
                    'name' => $name,
                    'secretFields' => array_keys($spec['secretFields']),
                    'publicFields' => array_keys($spec['publicFields']),
                    // Non-secret on/off switches (e.g. Instagram publishing), shown back.
                    'flagFields' => array_keys($spec['flagFields']),
                    'managed' => $status['managed'],
                    // A switch-only integration (Instagram) has no credentials of its own: it uses another app's.
                    // Otherwise managed rows read from the panel, else the environment, unless it is empty too.
                    'source' => $this->switchOnly($spec)
                        ? 'switch'
                        : ($status['managed']
                            ? 'panel'
                            : ($this->environmentConfigured($config, $spec) ? 'environment' : 'none')),
                    // Non-secret public identifiers, safe to show back to the operator.
                    'publicValues' => $status['publicValues'],
                    // A managed row shows its stored switches; otherwise the live (environment) value.
                    'flagValues' => $status['managed'] ? $status['flagValues'] : $this->environmentFlags($config, $spec),
                    // Which secret fields already hold a value; their inputs may be left blank to keep them.
                    'storedSecretFields' => $status['storedSecretFields'],
                    'enabled' => $status['managed'] ? $status['enabled'] : true,
                    'updatedAt' => $status['updatedAt'],
                ];
            }
        }

        return Inertia::render('Platform/Pages/Integrations', [
            'integrations' => $integrations,
        ]);
    }

    /**
     * Whether the integration is only a switch, with no credentials of its own (Instagram uses the Meta app).
     *
     * @param  array{secretFields: array<string, string>, publicFields: array<string, string>, flagFields: array<string, string>}  $spec
     */
    private function switchOnly(array $spec): bool
    {
        return $spec['secretFields'] === [] && $spec['publicFields'] === [];
    }

    /**
     * Whether the environment supplies a value for any of this integration's config keys. Only
     * reads whether a value is present, never what it is; no secret reaches the page.
     *
     * @param  array{secretFields: array<string, string>, publicFields: array<string, string>, flagFields: array<string, string>}  $spec
     */
    private function environmentConfigured(ConfigRepository $config, array $spec): bool
    {
        foreach ([...array_values($spec['secretFields']), ...array_values($spec['publicFields'])] as $configKey) {
            $value = $config->get($configKey);
            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        return in_array(true, $this->environmentFlags($config, $spec), true);
    }

    /**
     * The live value of each switch while no panel row manages it (the environment default).
     *
     * @param  array{secretFields: array<string, string>, publicFields: array<string, string>, flagFields: array<string, string>}  $spec
     * @return array<string, bool>
     */
    private function environmentFlags(ConfigRepository $config, array $spec): array
    {
        $flags = [];
        foreach ($spec['flagFields'] as $field => $configKey) {
            $flags[$field] = (bool) $config->get($configKey, false);
        }

        return $flags;
    }

    /**
     * Save an integration's credentials. Secret values are stored encrypted and never read back;
     * blank secret fields keep what is already stored. Public identifiers overwrite.
     */
    public function update(
        Request $request,
        string $group,
        string $name,
        IntegrationCredentials $credentials,
        AuditLogger $audit,
    ): RedirectResponse {
        $spec = IntegrationCredentials::SCHEMA[$group][$name] ?? abort(404);

        $rules = [
            'enabled' => ['required', 'boolean'],
            'secrets' => ['array'],
            'public' => ['array'],
            'flags' => ['array'],
        ];
        foreach (array_keys($spec['secretFields']) as $field) {
            $rules["secrets.{$field}"] = ['nullable', 'string', 'max:255'];
        }
        foreach (array_keys($spec['publicFields']) as $field) {
            $rules["public.{$field}"] = ['nullable', 'string', 'max:255'];
        }
        foreach (array_keys($spec['flagFields']) as $field) {
            $rules["flags.{$field}"] = ['nullable', 'boolean'];
        }

        $input = $request->validate($rules);

        $secrets = [];
        foreach (array_keys($spec['secretFields']) as $field) {
            $secrets[$field] = (string) ($input['secrets'][$field] ?? '');
        }

        $public = [];
        foreach (array_keys($spec['publicFields']) as $field) {
            $public[$field] = (string) ($input['public'][$field] ?? '');
        }

        $flags = [];
        foreach (array_keys($spec['flagFields']) as $field) {
            $flags[$field] = (bool) ($input['flags'][$field] ?? false);
        }

        $credentials->save($group, $name, $secrets, $public, (bool) $input['enabled'], $flags);

        // Never log the secret values: only which integration and its non-secret flags.
        $audit->record('platform.integration.updated', null, [
            'group' => $group,
            'name' => $name,
            'enabled' => (bool) $input['enabled'],
            'flags' => $flags,
        ]);

        Toast::success(__('Integration credentials saved.'));

        return back();
    }

    /**
     * Forget an integration's panel credentials; it falls back to the environment.
     */
    public function clear(
        string $group,
        string $name,
        IntegrationCredentials $credentials,
        AuditLogger $audit,
    ): RedirectResponse {
        abort_unless(isset(IntegrationCredentials::SCHEMA[$group][$name]), 404);

        $credentials->clear($group, $name);
        $audit->record('platform.integration.cleared', null, ['group' => $group, 'name' => $name]);

        Toast::success(__('Integration credentials cleared.'));

        return back();
    }
}
