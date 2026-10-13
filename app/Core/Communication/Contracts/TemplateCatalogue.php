<?php

declare(strict_types=1);

namespace App\Core\Communication\Contracts;

use App\Core\Communication\Data\Template\ProviderTemplateStatus;
use App\Core\Communication\Data\Template\TemplateCatalogueResult;
use App\Core\Communication\Data\Template\TemplateDraft;

/**
 * The provider's own catalogue of WhatsApp message templates (ADR 0022): list, create (submit for approval) and
 * delete, through Meta's Business Management API for the workspace's WhatsApp Business Account
 * (`GET|POST|DELETE /{business_account_id}/message_templates`).
 *
 * The communication app implements it with the credential it holds; no app ever reads a token. Nothing is
 * stored: the provider is the source of truth, and approval changes also arrive as `template_status`
 * channel events. An app mirrors what it needs.
 *
 * Honest states ({@see TemplateCatalogueResult::$status}): `module_unavailable` (no communication app or not
 * entitled), `not_configured` (no WhatsApp connection with a resolvable credential), `missing_business_account`
 * (the connection has no WhatsApp Business Account id; the phone number id is not enough for Meta's template
 * API). A call never fakes a result. Authorization (who may manage templates) is the caller's job. Call inside
 * the active tenant context.
 */
interface TemplateCatalogue
{
    public const int MAX_PAGE = 100;

    /**
     * @param  string|null  $name  Filter by (partial) template name.
     * @param  string|null  $after  The `nextCursor` of the previous page.
     */
    public function list(?ProviderTemplateStatus $status = null, ?string $name = null, int $limit = 50, ?string $after = null): TemplateCatalogueResult;

    /** Submit a template for approval. The result's template carries the provider id and `pending` (or what the provider answered). */
    public function create(TemplateDraft $draft): TemplateCatalogueResult;

    /** Delete every language version of the template name (Meta's delete by name), or one by provider id. */
    public function delete(string $name, ?string $providerTemplateId = null): TemplateCatalogueResult;
}
