<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Contracts;

use App\Core\Communication\Data\Template\ProviderTemplateStatus;
use App\Core\Communication\Data\Template\TemplateCatalogueResult;
use App\Core\Communication\Data\Template\TemplateDraft;
use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Models\ChannelConnection;

/**
 * A provider that manages message templates through its own business-management API (WhatsApp Cloud API:
 * `/{business_account_id}/message_templates`). Answers the core catalogue result; never throws for provider
 * failures and never puts the credential in a result.
 */
interface ManagesTemplates
{
    public function listTemplates(ChannelConnection $connection, ChannelCredentials $credentials, string $businessAccountId, ?ProviderTemplateStatus $status, ?string $name, int $limit, ?string $after): TemplateCatalogueResult;

    public function createTemplate(ChannelConnection $connection, ChannelCredentials $credentials, string $businessAccountId, TemplateDraft $draft): TemplateCatalogueResult;

    public function deleteTemplate(ChannelConnection $connection, ChannelCredentials $credentials, string $businessAccountId, string $name, ?string $providerTemplateId): TemplateCatalogueResult;
}
