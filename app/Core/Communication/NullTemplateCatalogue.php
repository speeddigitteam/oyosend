<?php

declare(strict_types=1);

namespace App\Core\Communication;

use App\Core\Communication\Contracts\TemplateCatalogue;
use App\Core\Communication\Data\Template\ProviderTemplateStatus;
use App\Core\Communication\Data\Template\TemplateCatalogueResult;
use App\Core\Communication\Data\Template\TemplateCatalogueStatus;
use App\Core\Communication\Data\Template\TemplateDraft;

/** Bound when no communication module is installed: there is no provider catalogue, and it says so. */
final class NullTemplateCatalogue implements TemplateCatalogue
{
    public function list(?ProviderTemplateStatus $status = null, ?string $name = null, int $limit = 50, ?string $after = null): TemplateCatalogueResult
    {
        return new TemplateCatalogueResult(TemplateCatalogueStatus::ModuleUnavailable, 'module_unavailable');
    }

    public function create(TemplateDraft $draft): TemplateCatalogueResult
    {
        return new TemplateCatalogueResult(TemplateCatalogueStatus::ModuleUnavailable, 'module_unavailable');
    }

    public function delete(string $name, ?string $providerTemplateId = null): TemplateCatalogueResult
    {
        return new TemplateCatalogueResult(TemplateCatalogueStatus::ModuleUnavailable, 'module_unavailable');
    }
}
