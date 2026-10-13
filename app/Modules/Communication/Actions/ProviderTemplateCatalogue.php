<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Communication\Contracts\TemplateCatalogue;
use App\Core\Communication\Data\Template\ProviderTemplateStatus;
use App\Core\Communication\Data\Template\TemplateCatalogueResult;
use App\Core\Communication\Data\Template\TemplateCatalogueStatus;
use App\Core\Communication\Data\Template\TemplateDraft;
use App\Modules\Communication\Channels\Contracts\ManagesTemplates;
use App\Modules\Communication\Channels\CredentialResolver;
use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConfigurationState;
use App\Modules\Communication\Models\ChannelConnection;

/**
 * The implementation of the core {@see TemplateCatalogue}: the provider's own template catalogue for the
 * workspace's WhatsApp Business Account. The credential is resolved here and handed to the adapter for one call;
 * it never reaches the caller or a result.
 *
 * Honest states, in this order: not entitled (`module_unavailable`), no usable WhatsApp connection or a provider
 * that cannot manage templates (`not_configured`), no `business_account_id` setting (`missing_business_account`).
 */
final class ProviderTemplateCatalogue implements TemplateCatalogue
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly ChannelConnections $connections,
        private readonly CredentialResolver $credentials,
        private readonly AuditLogger $audit,
    ) {}

    public function list(?ProviderTemplateStatus $status = null, ?string $name = null, int $limit = 50, ?string $after = null): TemplateCatalogueResult
    {
        return $this->with(fn (ManagesTemplates $provider, ChannelConnection $connection, ChannelCredentials $credentials, string $account): TemplateCatalogueResult => $provider->listTemplates(
            $connection,
            $credentials,
            $account,
            $status,
            $name,
            max(1, min(self::MAX_PAGE, $limit)),
            $after,
        ));
    }

    public function create(TemplateDraft $draft): TemplateCatalogueResult
    {
        $result = $this->with(fn (ManagesTemplates $provider, ChannelConnection $connection, ChannelCredentials $credentials, string $account): TemplateCatalogueResult => $provider->createTemplate($connection, $credentials, $account, $draft));

        if ($result->isOk()) {
            $this->audit->record('communication.template.provider_submitted', null, [
                'name' => $draft->name,
                'language' => $draft->language,
                'category' => $draft->category->value,
            ], subjectLabel: $draft->name);
        }

        return $result;
    }

    public function delete(string $name, ?string $providerTemplateId = null): TemplateCatalogueResult
    {
        if (preg_match('/^[a-z0-9_]{1,512}$/', $name) !== 1) {
            return new TemplateCatalogueResult(TemplateCatalogueStatus::Rejected, 'invalid_name');
        }

        $result = $this->with(fn (ManagesTemplates $provider, ChannelConnection $connection, ChannelCredentials $credentials, string $account): TemplateCatalogueResult => $provider->deleteTemplate($connection, $credentials, $account, $name, $providerTemplateId));

        if ($result->isOk()) {
            $this->audit->record('communication.template.provider_deleted', null, ['name' => $name], subjectLabel: $name);
        }

        return $result;
    }

    /**
     * @param  callable(ManagesTemplates, ChannelConnection, ChannelCredentials, string): TemplateCatalogueResult  $call
     */
    private function with(callable $call): TemplateCatalogueResult
    {
        if (! $this->access->entitled()) {
            return new TemplateCatalogueResult(TemplateCatalogueStatus::ModuleUnavailable, 'module_unavailable');
        }

        ['connection' => $connection, 'provider' => $provider] = $this->connections->resolve(Channel::Whatsapp);

        if (! $connection->is_enabled || ! $provider instanceof ManagesTemplates
            || $this->connections->stateOf($connection, $provider) === ConfigurationState::NotConfigured) {
            return new TemplateCatalogueResult(TemplateCatalogueStatus::NotConfigured, 'provider_not_configured');
        }

        $account = $connection->settings['business_account_id'] ?? null;

        if (! is_string($account) || preg_match('/^\d{5,32}$/', $account) !== 1) {
            return new TemplateCatalogueResult(TemplateCatalogueStatus::MissingBusinessAccount, 'missing_business_account_id');
        }

        $credentials = $this->credentials->resolve($connection->credential_ref, $connection->provider);

        if ($credentials === null) {
            return new TemplateCatalogueResult(TemplateCatalogueStatus::NotConfigured, 'provider_not_configured');
        }

        return $call($provider, $connection, $credentials, $account);
    }
}
